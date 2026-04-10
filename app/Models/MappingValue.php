<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MappingValue extends Model
{
    use HasFactory;

    protected $table = 'mapping_value';

    protected $guarded = ['id'];
    protected $fillable = [
        'parent_id',
        'sequence',
        'key',
        'value',
        'label',
        'option1',
        'option2',
        'option3',
        'option4',
    ];
}
