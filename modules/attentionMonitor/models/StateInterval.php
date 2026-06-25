<?php

namespace app\modules\attentionMonitor\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * A single closed run of one state (run-length encoded observation).
 *
 * @property int $id
 * @property int $session_id
 * @property string $state
 * @property int $started_at
 * @property int $ended_at
 */
class StateInterval extends ActiveRecord
{
    const STATE_ENGAGED = 'engaged';
    const STATE_DISTRACTED = 'distracted';
    const STATE_ABSENT = 'absent';

    const STATES = [self::STATE_ENGAGED, self::STATE_DISTRACTED, self::STATE_ABSENT];

    public static function tableName()
    {
        return '{{%am_state_interval}}';
    }

    public function rules()
    {
        return [
            [['session_id', 'state', 'started_at', 'ended_at'], 'required'],
            [['session_id', 'started_at', 'ended_at'], 'integer'],
            [['state'], 'in', 'range' => self::STATES],
            [['ended_at'], 'compare', 'compareAttribute' => 'started_at', 'operator' => '>='],
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getSession()
    {
        return $this->hasOne(Session::class, ['id' => 'session_id']);
    }

    public function getDuration(): int
    {
        return $this->ended_at - $this->started_at;
    }
}
