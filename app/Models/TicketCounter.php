<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 每營業日、每佇列的號碼計數器。
 *
 * @property int $id
 * @property string $business_date
 * @property string $queue
 * @property int $last_number
 */
#[Fillable(['business_date', 'queue', 'last_number'])]
class TicketCounter extends Model
{
    public $timestamps = false;
}
