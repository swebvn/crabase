<?php
namespace app\model;

final class AuthSession extends Model
{
    protected $table = 'auth_sessions';
    protected $primaryKey = 'token_hash';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['token_hash', 'user_id', 'expires'];
    protected $hidden = ['token_hash'];
}
