<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Column Reduction — worklists / test_samples / test_sample_items
 *
 * Schema-accurate against the live phpMyAdmin dump (2026-11-18).
 * Every step checks the current structure first, so this migration is
 * RE-RUNNABLE and safe even if the older 2026_11_17 draft already ran.
 *
 * worklists         DROP: order_id (+ its UNIQUE index), uuid_new,
 *                         sample_number (+ its INDEX), order_date,
 *                         parameters, result_data, reassignment_history
 *                   ADD : machine_mapping_id, cancelled_reason, latest_result_id,
 *                         result_count, source
 *                   IDX : +uq_worklist_item_machine  UNIQUE (test_sample_item_id, machine_id)
 *                         +idx_worklist_machine_status_pushed (machine_id, status, pushed_at)
 *                         +idx_worklist_latest_result
 *                   KEEP: barcode, receipt_barcode, suffix, timing (Mirth OML payload — §1.7.7)
 * test_samples      DROP: sl_no
 * test_sample_items DROP: sample_number
 *                   KEEP: suffix (NEEDED — tube identity; re-added if the older
 *                         2026_11_17 draft already dropped it)
 * new table         worklist_reassignments (optional audit — delete the call in up() if not needed)
 *
 * Run the duplicate pre-check (guide §15) BEFORE this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reduceWorklists();
        $this->reduceTestSamples();
        $this->reduceTestSampleItems();
        $this->createReassignmentAuditTable();
    }

    public function down(): void
    {
        // Restores STRUCTURE only — data of the dropped columns is NOT recoverable.
        // Full data restore: mysql -u USER -p DBNAME < database/before_column_reduction.sql
        Schema::dropIfExists('worklist_reassignments');
        $this->restoreTestSampleItems();
        $this->restoreTestSamples();
        $this->restoreWorklists();
    }

    /* ───────────────────────────── up ───────────────────────────── */

    private function reduceWorklists(): void
    {
        // 1) indexes tied to columns being dropped.
        //    MySQL auto-drops a single-column index when its column is dropped;
        //    these explicit drops are name-tolerant and keep SHOW INDEX tidy.
        $this->dropIndexIfExact('worklists', ['worklists_order_id_unique', 'order_id', 'order_id_unique']);
        $this->dropIndexIfExact('worklists', ['worklists_sample_number_index', 'sample_number', 'sample_number_index']);

        // 2) drop the dead columns (only the ones that still exist)
        $drop = array_values(array_filter([
            'order_id',
            'uuid_new',
            'sample_number',
            'order_date',
            'parameters',
            'result_data',
            'reassignment_history',
        ], fn (string $c): bool => Schema::hasColumn('worklists', $c)));

        if ($drop !== []) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropColumn($drop));
        }

        // 3) promoted + multi-version result-pointer columns
        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'machine_mapping_id')) {
                $t->char('machine_mapping_id', 36)->nullable()->after('machine_id')
                    ->comment('machine_test_mappings(id) used for this row (was inside parameters JSON)');
            }
            if (! Schema::hasColumn('worklists', 'cancelled_reason')) {
                $t->string('cancelled_reason', 255)->nullable()->after('status');
            }
            if (! Schema::hasColumn('worklists', 'latest_result_id')) {
                $t->char('latest_result_id', 36)->nullable()->after('cancelled_reason')
                    ->comment('lis_results(id) — newest result version');
            }
            if (! Schema::hasColumn('worklists', 'result_count')) {
                $t->unsignedInteger('result_count')->default(0)->after('latest_result_id');
            }
            if (! Schema::hasColumn('worklists', 'source')) {
                $t->string('source', 20)->default('fanout')->after('result_count')
                    ->comment('fanout | manual | mirth | recheck');
            }
        });

        // 4) hot-path indexes
        Schema::table('worklists', function (Blueprint $t): void {
            if (! $this->indexExists('worklists', 'idx_worklist_machine_status_pushed')) {
                // Mirth pull: WHERE machine_id = ? AND status = 'pending' AND pushed_at IS NULL
                $t->index(['machine_id', 'status', 'pushed_at'], 'idx_worklist_machine_status_pushed');
            }
            if (! $this->indexExists('worklists', 'idx_worklist_latest_result')) {
                $t->index('latest_result_id', 'idx_worklist_latest_result');
            }
        });

        // 5) one worklist per sample-item per machine (duplicate guard — §1.4).
        //    Runs the duplicate pre-check first and FAILS with the offending
        //    rows if production data would violate the constraint.
        if (! $this->indexExists('worklists', 'uq_worklist_item_machine')) {
            $this->assertNoItemMachineDuplicates();

            Schema::table('worklists', fn (Blueprint $t) =>
                $t->unique(['test_sample_item_id', 'machine_id'], 'uq_worklist_item_machine')
            );
        }
    }

    private function reduceTestSamples(): void
    {
        if (Schema::hasColumn('test_samples', 'sl_no')) {
            Schema::table('test_samples', fn (Blueprint $t) => $t->dropColumn('sl_no'));
        }
    }

    private function reduceTestSampleItems(): void
    {
        // suffix is NEEDED — never dropped. If the older 2026_11_17 draft already
        // dropped it, re-add it here (structure only; old values are not recoverable).
        if (! Schema::hasColumn('test_sample_items', 'suffix')) {
            Schema::table('test_sample_items', fn (Blueprint $t) => $t->string('suffix', 20)->nullable());
        }

        if (Schema::hasColumn('test_sample_items', 'sample_number')) {
            // MySQL auto-drops the single-column index on this column
            Schema::table('test_sample_items', fn (Blueprint $t) => $t->dropColumn('sample_number'));
        }
    }

    private function createReassignmentAuditTable(): void
    {
        // Optional (§1.7.3) — replaces the dropped reassignment_history JSON.
        // Delete the call in up() if you don't audit machine reassignments.
        if (! Schema::hasTable('worklist_reassignments')) {
            Schema::create('worklist_reassignments', function (Blueprint $t): void {
                $t->char('id', 36)->primary();
                $t->char('worklist_id', 36);
                $t->char('from_machine_id', 36);
                $t->char('to_machine_id', 36);
                $t->string('reason', 255)->nullable();
                $t->timestamps();
                $t->index('worklist_id', 'idx_reassign_worklist');
            });
        }
    }

    /* ──────────────────────────── down ──────────────────────────── */

    private function restoreWorklists(): void
    {
        if ($this->indexExists('worklists', 'uq_worklist_item_machine')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropUnique('uq_worklist_item_machine'));
        }
        if ($this->indexExists('worklists', 'idx_worklist_machine_status_pushed')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropIndex('idx_worklist_machine_status_pushed'));
        }
        if ($this->indexExists('worklists', 'idx_worklist_latest_result')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->dropIndex('idx_worklist_latest_result'));
        }

        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'machine_mapping_id')) {
                $t->char('machine_mapping_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'cancelled_reason')) {
                $t->string('cancelled_reason', 255)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'latest_result_id')) {
                $t->char('latest_result_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'result_count')) {
                $t->unsignedInteger('result_count')->default(0);
            }
            if (! Schema::hasColumn('worklists', 'source')) {
                $t->string('source', 20)->default('fanout');
            }
        });

        // columns dropped by up() — structure only, old data is gone
        Schema::table('worklists', function (Blueprint $t): void {
            if (! Schema::hasColumn('worklists', 'order_id')) {
                $t->string('order_id', 36)->nullable();
            }
            if (! Schema::hasColumn('worklists', 'uuid_new')) {
                $t->uuid('uuid_new')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'sample_number')) {
                $t->integer('sample_number')->default(1);
            }
            if (! Schema::hasColumn('worklists', 'order_date')) {
                // was NOT NULL originally; nullable here because the table may already hold rows
                $t->timestamp('order_date')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'parameters')) {
                $t->longText('parameters')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'result_data')) {
                $t->longText('result_data')->nullable();
            }
            if (! Schema::hasColumn('worklists', 'reassignment_history')) {
                $t->longText('reassignment_history')->nullable();
            }
        });

        if (Schema::hasColumn('worklists', 'order_id') && ! $this->indexExists('worklists', 'worklists_order_id_unique')) {
            Schema::table('worklists', fn (Blueprint $t) => $t->unique('order_id', 'worklists_order_id_unique'));
        }
    }

    private function restoreTestSamples(): void
    {
        Schema::table('test_samples', function (Blueprint $t): void {
            if (! Schema::hasColumn('test_samples', 'sl_no')) {
                $t->string('sl_no', 100)->nullable();
            }
        });
    }

    private function restoreTestSampleItems(): void
    {
        // suffix is NOT restored here — up() never drops it (it is needed).
        Schema::table('test_sample_items', function (Blueprint $t): void {
            if (! Schema::hasColumn('test_sample_items', 'sample_number')) {
                $t->integer('sample_number')->nullable();
            }
        });
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function indexExists(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (bool) DB::selectOne(
                "SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index]
            )->c;
        }

        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }

    private function dropIndexIfExact(string $table, array $candidates): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return; // test runs — dropping the column removes single-column indexes automatically
        }

        foreach ($candidates as $name) {
            if ($this->indexExists($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");

                return;
            }
        }
    }

    private function assertNoItemMachineDuplicates(): void
    {
        $dupes = DB::select(
            'SELECT test_sample_item_id, machine_id, COUNT(*) AS n
             FROM worklists
             WHERE test_sample_item_id IS NOT NULL
             GROUP BY test_sample_item_id, machine_id
             HAVING n > 1
             LIMIT 5'
        );

        if ($dupes !== []) {
            $rows = implode('; ', array_map(
                fn ($r) => "item={$r->test_sample_item_id} machine={$r->machine_id} x{$r->n}",
                $dupes
            ));

            throw new RuntimeException(
                'UNIQUE (test_sample_item_id, machine_id) blocked: duplicate worklists found. ' .
                'Cancel the older duplicates (guide §15 step 4), then re-run migrate. Offenders: ' . $rows
            );
        }
    }
};
