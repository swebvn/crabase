<?php
namespace app\model;

final class Account extends Model
{
    protected $table = 'accounts';
    protected $primaryKey = 'user_id';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['user_id', 'email', 'password_hash', 'admin', 'enabled', 'git_name', 'git_email'];
    protected $hidden = ['password_hash'];
}
