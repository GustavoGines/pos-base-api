<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Product;
use Carbon\Carbon;

class PruneTrashCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trash:prune {--days=30 : The number of days to keep items in the trash}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete items that have been in the trash for longer than the specified number of days.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $date = Carbon::now()->subDays($days);

        $this->info("Pruning trashed items older than {$days} days ({$date->toDateTimeString()})...");

        // Prune Customers
        $customersDeleted = Customer::onlyTrashed()
            ->where('deleted_at', '<', $date)
            ->forceDelete();
            
        $this->info("Deleted {$customersDeleted} customers.");

        // Prune Products
        $productsDeleted = Product::onlyTrashed()
            ->where('deleted_at', '<', $date)
            ->forceDelete();
            
        $this->info("Deleted {$productsDeleted} products.");

        $this->info("Trash pruning completed.");
    }
}
