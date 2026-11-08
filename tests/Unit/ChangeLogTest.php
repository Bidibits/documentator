<?php

namespace Tests\Unit;

use App\Support\ChangeLog;
use PHPUnit\Framework\TestCase;

/** The rules for what counts as a change (the same ones KVNsys's audit trail follows). */
class ChangeLogTest extends TestCase
{
    public function test_a_new_record_logs_each_column_that_has_a_value_and_skips_empty_ones(): void
    {
        $changes = ChangeLog::onCreate(['code' => 'A-1', 'title' => 'Lease', 'department' => null, 'description' => '', 'rev_no' => 0]);

        $this->assertSame([
            ['column' => 'code', 'before' => null, 'after' => 'A-1', 'type' => 'Created'],
            ['column' => 'title', 'before' => null, 'after' => 'Lease', 'type' => 'Created'],
            ['column' => 'rev_no', 'before' => null, 'after' => '0', 'type' => 'Created'],
        ], $changes);
    }

    public function test_an_update_logs_only_the_columns_whose_value_differs(): void
    {
        $changes = ChangeLog::onUpdate(
            ['title' => 'Lease', 'department' => 'HR', 'description' => null],
            ['title' => 'Lease 2026', 'department' => 'HR', 'description' => ''],
        );

        $this->assertSame([['column' => 'title', 'before' => 'Lease', 'after' => 'Lease 2026', 'type' => 'Updated']], $changes);
    }

    public function test_a_column_missing_from_before_was_empty(): void
    {
        $this->assertSame(
            [['column' => 'location_note', 'before' => null, 'after' => 'Cabinet 3', 'type' => 'Updated']],
            ChangeLog::onUpdate([], ['location_note' => 'Cabinet 3']),
        );
    }

    public function test_a_password_is_logged_as_hidden_but_the_fact_that_it_changed_is_kept(): void
    {
        $changes = ChangeLog::onUpdate(['password' => 'hash-one'], ['password' => 'hash-two']);

        $this->assertSame([['column' => 'password', 'before' => '(hidden)', 'after' => '(hidden)', 'type' => 'Updated']], $changes);
        $this->assertSame([], ChangeLog::onUpdate(['password' => 'same'], ['password' => 'same']));
    }

    public function test_true_and_false_are_logged_in_words_whether_the_database_says_1_or_true(): void
    {
        $this->assertSame('true', ChangeLog::onCreate(['active' => 1])[0]['after']);
        $this->assertSame([], ChangeLog::onUpdate(['active' => 1], ['active' => true]));
        $this->assertSame('false', ChangeLog::onUpdate(['active' => 1], ['active' => 0])[0]['after']);
    }
}
