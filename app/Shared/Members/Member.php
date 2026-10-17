<?php

declare(strict_types=1);

namespace App\Shared\Members;

use App\Shared\Support\Timestamps;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
final class Member extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $table = 'members';

    protected $dateFormat = Timestamps::FORMAT;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password'];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
