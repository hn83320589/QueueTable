<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 兩張可以併桌的相鄰桌（table_a_id < table_b_id）。
 *
 * @property int $id
 * @property int $table_a_id
 * @property int $table_b_id
 */
#[Fillable(['table_a_id', 'table_b_id'])]
class TableAdjacency extends Model
{
    public $timestamps = false;
}
