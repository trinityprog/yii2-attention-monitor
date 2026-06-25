<?php

use yii\db\Migration;

/**
 * Creates the am_state_interval table. Observations are stored as closed
 * state intervals (run-length encoded), not one row per second: the client
 * only emits a row when the state actually changes, which keeps row counts
 * proportional to the number of transitions during a lesson rather than to
 * its duration - the difference that matters once ~30 students are tracked
 * at once.
 *
 * The foreign key is declared inline inside createTable() because SQLite
 * only accepts FK constraints at table-creation time; ALTER TABLE ADD
 * CONSTRAINT is not supported by its query builder.
 */
class m260623_173327_create_am_state_interval_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%am_state_interval}}', [
            'id' => $this->primaryKey(),
            'session_id' => $this->integer()->notNull(),
            // engaged | distracted | absent
            'state' => $this->string(16)->notNull(),
            'started_at' => $this->integer()->notNull(),
            'ended_at' => $this->integer()->notNull(),
            'FOREIGN KEY ([[session_id]]) REFERENCES {{%am_session}} ([[id]]) ON DELETE CASCADE',
        ]);

        $this->createIndex('idx-am_state_interval-session_id-started_at', '{{%am_state_interval}}', ['session_id', 'started_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%am_state_interval}}');
    }
}
