<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteContactSetting extends Model
{
    protected $fillable = ['phone', 'whatsapp', 'email'];
}
