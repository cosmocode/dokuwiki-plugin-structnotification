<?php

/**
 * DokuWiki Plugin structnotification (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\plugin\structgroup\types\Group;
use dokuwiki\plugin\struct\meta\Search;
use dokuwiki\plugin\struct\meta\Value;

class action_plugin_structnotification_notification extends ActionPlugin
{
    /**
     * Registers a callback function for a given event
     *
     * @param EventHandler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PLUGIN_NOTIFICATION_REGISTER_SOURCE', 'AFTER', $this, 'addNotificationsSource');
        $controller->register_hook('PLUGIN_NOTIFICATION_GATHER', 'AFTER', $this, 'addNotifications');
        $controller->register_hook(
            'PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES',
            'AFTER',
            $this,
            'addNotificationCacheDependencies'
        );
    }

    public function addNotificationsSource(Event $event)
    {
        $event->data[] = 'structnotification';
    }

    public function addNotificationCacheDependencies(Event $event)
    {
        if (!in_array('structnotification', $event->data['plugins'])) return;

        try {
            /** @var \helper_plugin_structnotification_db $db_helper */
            $db_helper = plugin_load('helper', 'structnotification_db');
            $sqlite = $db_helper->getDB();
            $event->data['dependencies'][] = $sqlite->getAdapter()->getDbFile();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }
    }

    protected function getValueByLabel($values, $label)
    {
        /* @var Value $value */
        foreach ($values as $value) {
            $colLabel = $value->getColumn()->getLabel();
            if ($colLabel == $label) {
                return $value->getRawValue();
            }
        }
        //nothing found
        throw new Exception("column: $label not found in values");
    }

    public function addNotifications(Event $event)
    {
        if (!in_array('structnotification', $event->data['plugins'])) return;

        try {
            /** @var \helper_plugin_structnotification_db$db_helper */
            $db_helper = plugin_load('helper', 'structnotification_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

        $user = $event->data['user'];

        $q = 'SELECT * FROM predicate';
        $res = $sqlite->query($q);

        $predicates = $sqlite->res2arr($res);

        foreach ($predicates as $predicate) {
            $schema = $predicate['schema'];
            $field = $predicate['field'];
            $operator = $predicate['operator'];
            $value = $predicate['value'];
            $filters = $predicate['filters'];
            $users_and_groups = $predicate['users_and_groups'];
            $message = $predicate['message'];

            try {
                $search = new Search();
                foreach (explode(',', $schema) as $table) {
                    $search->addSchema($table);
                    $search->addColumn($table . '.*');
                }
                // add special columns
                $special_columns = ['%pageid%', '%title%', '%lastupdate%', '%lasteditor%', '%lastsummary%', '%rowid%'];
                foreach ($special_columns as $special_column) {
                    $search->addColumn($special_column);
                }
                $this->addFiltersToSearch($search, $filters);

                // temporary workaround for GETACCESSLEVEL check in struct queries in closed wikis:
                // cli user has undefined permissions, so we disable ACLs to get any results at all
                if (PHP_SAPI === 'cli') {
                    global $conf;
                    $origACL = $conf['useacl'];
                    $conf['useacl'] = false;
                }

                $result = $search->getRows();
                $result_pids = $search->getPids();

                // restore ACL setting
                if (PHP_SAPI === 'cli') {
                    $conf['useacl'] = $origACL;
                }

                /* @var Value[] $row */
                $counter = count($result);

                /* @var Value[] $row */
                for ($i = 0; $i < $counter; $i++) {
                    $values = $result[$i];
                    $pid = $result_pids[$i];

                    $users_set = $this->usersSet($users_and_groups, $values);
                    if (!isset($users_set[$user])) continue;

                    $rawDate = $this->getValueByLabel($values, $field);
                    if ($this->predicateTrue($rawDate, $operator, $value)) {
                        $message_with_replacements = $this->replacePlaceholders($message, $values);
                        $message_with_replacements_html = p_render(
                            'xhtml',
                            p_get_instructions($message_with_replacements),
                            $info
                        );
                        $event->data['notifications'][] = [
                            'plugin' => 'structnotification',
                            'id' => $predicate['id'] . ':' . $schema . ':' . $pid . ':'  . $rawDate,
                            'full' => $message_with_replacements_html,
                            'brief' => $message_with_replacements_html,
                            'timestamp' => (int) strtotime($rawDate)
                        ];
                    }
                }
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }
        }
    }

    /**
     * @return array
     */
    protected function usersSet($user_and_groups, $values)
    {
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;

        // $auth is missing in CLI context
        if (is_null($auth)) {
            auth_setup();
        }

        //make substitutions
        $user_and_groups = preg_replace_callback(
            '/@@(.*?)@@/',
            function ($matches) use ($values) {
                [$schema, $field] = explode('.', trim($matches[1]));
                if (!$field) return '';
                /* @var Value $value */
                foreach ($values as $value) {
                    $column = $value->getColumn();
                    $colLabel = $column->getLabel();
                    $type = $column->getType();
                    if ($colLabel == $field) {
                        if (
                            class_exists('\dokuwiki\plugin\structgroup\types\Group') &&
                            $type instanceof Group
                        ) {
                            if ($column->isMulti()) {
                                return implode(
                                    ',',
                                    array_map(static fn($rawValue) => '@' . $rawValue, $value->getRawValue())
                                );
                            } else {
                                return '@' . $value->getRawValue();
                            }
                        }
                        if ($column->isMulti()) {
                            return implode(',', $value->getRawValue());
                        } else {
                            return $value->getRawValue();
                        }
                    }
                }
                return '';
            },
            $user_and_groups
        );

        $user_and_groups_set = array_map('trim', explode(',', $user_and_groups));
        $users = [];
        $groups = [];
        foreach ($user_and_groups_set as $user_or_group) {
            if ($user_or_group[0] == '@') {
                $groups[] = substr($user_or_group, 1);
            } else {
                $users[] = $user_or_group;
            }
        }
        $set = [];

        $all_users = $auth->retrieveUsers();
        foreach ($all_users as $user => $info) {
            if (in_array($user, $users)) {
                $set[$user] = $info;
            } elseif (array_intersect($groups, $info['grps'])) {
                $set[$user] = $info;
            }
        }

        return $set;
    }

    protected function predicateTrue($date, $operator, $value)
    {
        $date = date('Y-m-d', strtotime($date));

        switch ($operator) {
            case 'before':
                $days = date('Y-m-d', strtotime("+$value days"));
                return $days >= $date;
            case 'after':
                $days = date('Y-m-d', strtotime("-$value days"));
                return $date <= $days;
            case 'at':
                $now = new DateTime();
                $at = new DateTime(date($value, strtotime($date)));
                return $now >= $at;
            default:
                return false;
        }
    }

    protected function replacePlaceholders($message, $values)
    {
        $patterns = [];
        $replacements = [];
        /* @var Value $value */
        foreach ($values as $value) {
            $schema = $value->getColumn()->getTable();
            $label = $value->getColumn()->getLabel();
            $patterns[] = "/@@$schema.$label@@/";
            $replacements[] = $value->getDisplayValue();
        }

        return preg_replace($patterns, $replacements, $message);
    }

    /**
     * @param Search $search
     * @param string $filters
     */
    protected function addFiltersToSearch(&$search, $filters)
    {
        if (!$filters) return;

        /** @var \helper_plugin_struct_config $confHelper */
        $confHelper = plugin_load('helper', 'struct_config');

        $filterConfigs = explode("\r\n", $filters);

        foreach ($filterConfigs as $config) {
            [$colname, $comp, $value, ] = $confHelper->parseFilterLine('AND', $config);
            $search->addFilter($colname, $value, $comp, 'AND');
        }
    }
}
