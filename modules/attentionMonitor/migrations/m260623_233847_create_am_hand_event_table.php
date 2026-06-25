<?php

use yii\db\Migration;

/**
 * Bonus feature: raised-hand episodes. Independent of am_state_interval -
 * a student can be "engaged" while also holding a hand up, so this is a
 * separate RLE stream rather than a fourth engagement state.
 */
class m260623_233847_create_am_hand_event_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%am_hand_event}}', [
            'id' => $this->primaryKey(),
            'session_id' => $this->integer()->notNull(),
            'started_at' => $this->integer()->notNull(),
            'ended_at' => $this->integer()->notNull(),
            'FOREIGN KEY ([[session_id]]) REFERENCES {{%am_session}} ([[id]]) ON DELETE CASCADE',
        ]);

        $this->createIndex('idx-am_hand_event-session_id-started_at', '{{%am_hand_event}}', ['session_id', 'started_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%am_hand_event}}');
    }
}
