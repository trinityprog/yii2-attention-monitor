<?php

use yii\db\Migration;

/**
 * Creates the am_session table: one row per lesson observation session.
 */
class m260623_173326_create_am_session_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%am_session}}', [
            'id' => $this->primaryKey(),
            'student_id' => $this->string(64)->notNull(),
            'started_at' => $this->integer()->notNull(),
            'ended_at' => $this->integer()->null(),
            // active | finished
            'status' => $this->string(16)->notNull()->defaultValue('active'),
            // JSON snapshot of computed report stats, filled in on finish so
            // the report page does not need to recompute from scratch every view.
            'stats' => $this->text()->null(),
        ]);

        $this->createIndex('idx-am_session-student_id', '{{%am_session}}', 'student_id');
        $this->createIndex('idx-am_session-status', '{{%am_session}}', 'status');
    }

    public function safeDown()
    {
        $this->dropTable('{{%am_session}}');
    }
}
