<?php

namespace app\modules\attentionMonitor\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * A single closed episode of a raised hand (bonus "Активность" feature).
 * Independent of StateInterval - engagement state and hand-raise are
 * tracked as two separate RLE streams.
 *
 * @property int $id
 * @property int $session_id
 * @property int $started_at
 * @property int $ended_at
 */
class HandEvent extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%am_hand_event}}';
    }

    public function rules()
    {
        return [
            [['session_id', 'started_at', 'ended_at'], 'required'],
            [['session_id', 'started_at', 'ended_at'], 'integer'],
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
