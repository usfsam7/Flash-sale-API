<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Hold;
use App\Models\Product;

class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';
    protected $description = 'Release expired holds so stock becomes available';

    public function handle()
    {
        $expired = Hold::where('released', false)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $hold) {
            $hold->released = true;
            $hold->save();

            // Clear cache for related product (if the product still exists)
            if ($hold->product) {
                $hold->product->clearAvailabilityCache();
            } else {
                $this->warn("Hold {$hold->id} has no related product; skipping cache clear.");
            }
        }

        $this->info("Released {$expired->count()} expired holds.");
    }
}
