<?php

namespace dokuwiki\plugin\structnotification\test;

use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\meta\Column;

/**
 * Unit tests for structnotification plugin
 *
 * @group plugin_structnotification
 * @group plugins
 */
class NotificationTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['struct', 'structnotification'];

    /** @var \action_plugin_structnotification_notification */
    protected $action;

    public function setUp(): void
    {
        parent::setUp();
        $this->action = new \action_plugin_structnotification_notification();
    }

    /**
     * Test date parsing with different formats
     */
    public function test_predicateTrue_date_formats()
    {
        $datetime = date('Y-m-d H:i:s', strtotime('+5 days'));
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$datetime, 'before', 10]));

        $datetime = date('Y/d/m', strtotime('+5 days'));
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$datetime, 'before', 10]));
    }

    /**
     * Test predicateTrue() with 'before' operator
     */
    public function test_predicateTrue_before()
    {
        // Test date that is 5 days from now should match "10 days before"
        $futureDate = date('Y-m-d', strtotime('+5 days'));
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$futureDate, 'before', 10]));

        // Test date that is 15 days from now should not match "10 days before"
        $farFutureDate = date('Y-m-d', strtotime('+15 days'));
        $this->assertFalse($this->callPrivateMethod('predicateTrue', [$farFutureDate, 'before', 10]));

        // Test today should match "1 day before"
        $today = date('Y-m-d');
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$today, 'before', 1]));
    }

    /**
     * Test predicateTrue() with 'after' operator
     */
    public function test_predicateTrue_after()
    {
        // Test date that is 15 days ago should match "10 days after"
        $farPastDate = date('Y-m-d', strtotime('-15 days'));
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$farPastDate, 'after', 10]));

        // Test date that is 5 days ago should not match "10 days after"
        $pastDate = date('Y-m-d', strtotime('-5 days'));
        $this->assertFalse($this->callPrivateMethod('predicateTrue', [$pastDate, 'after', 10]));

        // Test today should not match "1 day after"
        $today = date('Y-m-d');
        $this->assertFalse($this->callPrivateMethod('predicateTrue', [$today, 'after', 1]));
    }

    /**
     * Test predicateTrue() with invalid operator
     */
    public function test_predicateTrue_invalid_operator()
    {
        $today = date('Y-m-d');
        $this->assertFalse($this->callPrivateMethod('predicateTrue', [$today, 'invalid', 5]));
    }

    /**
     * Test edge cases for predicateTrue()
     */
    public function test_predicateTrue_edge_cases()
    {
        // Test with 0 days - both should match today
        $today = date('Y-m-d');
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$today, 'before', 0]));
        $this->assertTrue($this->callPrivateMethod('predicateTrue', [$today, 'after', 0]));
    }

    /**
     * Test replacePlaceholders()
     */
    public function test_replacePlaceholders()
    {
        // Mock Value objects
        $value1 = $this->createMockValue('schema1', 'field1', 'Test Value 1');
        $value2 = $this->createMockValue('schema1', 'field2', 'Test Value 2');
        $values = [$value1, $value2];

        $message = 'Hello @@schema1.field1@@, your @@schema1.field2@@ is ready.';
        $expected = 'Hello Test Value 1, your Test Value 2 is ready.';

        $result = $this->callPrivateMethod('replacePlaceholders', [$message, $values]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test replacePlaceholders with no matches
     */
    public function test_replacePlaceholders_no_matches()
    {
        $value1 = $this->createMockValue('schema1', 'field1', 'Test Value 1');
        $values = [$value1];

        $message = 'Hello @@schema2.field2@@, no replacement here.';
        $expected = 'Hello @@schema2.field2@@, no replacement here.';

        $result = $this->callPrivateMethod('replacePlaceholders', [$message, $values]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test early return in addFiltersToSearch()
     */
    public function test_addFiltersToSearch_empty_filters()
    {
        $search = $this->createMock(\dokuwiki\plugin\struct\meta\Search::class);
        $search->expects($this->never())->method('addFilter');

        $this->callPrivateMethod('addFiltersToSearch', [&$search, '']);
    }

    /**
     * Test addFiltersToSearch method with actual filters
     */
    public function test_addFiltersToSearch_with_filters()
    {
        $search = $this->createMock(\dokuwiki\plugin\struct\meta\Search::class);

        // Track the calls to addFilter
        $callCount = 0;
        $expectedCalls = [
            ['field1', 'value1', '=', 'AND'],
            ['field2', '10', '>', 'AND']
        ];

        // Expect addFilter to be called twice (once for each filter line)
        $search->expects($this->exactly(2))
               ->method('addFilter')
               ->willReturnCallback(function($colname, $value, $comp, $logic) use (&$callCount, $expectedCalls) {
                   $this->assertEquals($expectedCalls[$callCount][0], $colname);
                   $this->assertEquals($expectedCalls[$callCount][1], $value);
                   $this->assertEquals($expectedCalls[$callCount][2], $comp);
                   $this->assertEquals($expectedCalls[$callCount][3], $logic);
                   $callCount++;
               });

        // Add filters
        $filters = "field1 = value1\r\nfield2 > 10";
        $this->callPrivateMethod('addFiltersToSearch', [&$search, $filters]);
    }

    /**
     * Helper method to call private methods
     */
    private function callPrivateMethod($methodName, $args = [])
    {
        $reflection = new \ReflectionClass($this->action);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->action, $args);
    }

    /**
     * Helper method to create mock Value objects
     */
    private function createMockValue($schema, $label, $rawValue)
    {
        $column = $this->createMock(Column::class);
        $column->method('getTable')->willReturn($schema);
        $column->method('getLabel')->willReturn($label);

        $value = $this->createMock(Value::class);
        $value->method('getColumn')->willReturn($column);
        $value->method('getRawValue')->willReturn($rawValue);
        $value->method('getDisplayValue')->willReturn($rawValue);

        return $value;
    }
}
