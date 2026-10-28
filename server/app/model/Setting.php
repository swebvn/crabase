<?php

namespace app\model;

final class Setting extends Model
{
    protected $table = 'settings';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'key';
    protected $fillable = ['key', 'value'];

    public static function put(string $key, string $value): void
    {
        \app\service\Store::notify(self::query()->upsert([['key'=>$key, 'value'=>$value]], ['key'], ['value']));
    }
}
