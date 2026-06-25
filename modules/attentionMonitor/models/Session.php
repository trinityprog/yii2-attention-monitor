<?php

namespace app\modules\attentionMonitor\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $student_id
 * @property int $started_at
 * @property int|null $ended_at
 * @property string $status
 * @property string|null $stats
 */
class Session extends ActiveRecord
{
    const STATUS_ACTIVE = 'active';
    const STATUS_FINISHED = 'finished';

    public static function tableName()
    {
        return '{{%am_session}}';
    }

    public function rules()
    {
        return [
            [['student_id', 'started_at'], 'required'],
            [['student_id'], 'string', 'max' => 64],
            [['started_at', 'ended_at'], 'integer'],
            [['status'], 'string', 'max' => 16],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_FINISHED]],
            [['stats'], 'string'],
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getIntervals()
    {
        return $this->hasMany(StateInterval::class, ['session_id' => 'id'])
            ->orderBy(['started_at' => SORT_ASC]);
    }

    /**
     * @return ActiveQuery
     */
    public function getHandEvents()
    {
        return $this->hasMany(HandEvent::class, ['session_id' => 'id'])
            ->orderBy(['started_at' => SORT_ASC]);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    /**
     * Decoded report stats cached at finish time, or null if not computed yet.
     */
    public function getCachedStats(): ?array
    {
        if ($this->stats === null) {
            return null;
        }

        $decoded = json_decode($this->stats, true);

        return is_array($decoded) ? $decoded : null;
    }
}
