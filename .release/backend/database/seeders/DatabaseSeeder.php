<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);

        $customer = User::updateOrCreate(
            ['email' => 'customer@droopnexa.test'],
            ['name' => 'Demo Customer', 'password' => 'password', 'role' => 'customer', 'status' => 'active'],
        );
        Wallet::firstOrCreate(['customer_id' => $customer->id]);

        User::updateOrCreate(
            ['email' => 'support@droopnexa.test'],
            ['name' => 'Demo Support', 'password' => 'password', 'role' => 'support', 'status' => 'active'],
        );

        User::updateOrCreate(
            ['email' => 'admin@droopnexa.test'],
            ['name' => 'Demo Admin', 'password' => 'password', 'role' => 'admin', 'status' => 'active'],
        );

        if (! $customer->orderRequests()->exists()) {
            $customer->orderRequests()->create([
                'reference' => 'REQ-DEMO001',
                'status' => 'submitted',
                'customer_note' => 'I would like help choosing between these options.',
                'submitted_at' => now(),
            ])->items()->createMany(Product::query()->take(3)->get()->values()->map(fn (Product $product, int $position) => [
                'product_id' => $product->id,
                'product_title_snapshot' => $product->title,
                'display_price_snapshot' => $product->display_price,
                'position' => $position,
            ])->all());
        }

        $rewardsCustomer = User::updateOrCreate(
            ['email' => 'rewards@droopnexa.test'],
            ['name' => 'Rewards Customer', 'password' => 'password', 'role' => 'customer', 'status' => 'active'],
        );
        $rewardsWallet = Wallet::firstOrCreate(['customer_id' => $rewardsCustomer->id]);
        $rewardsWallet->update(['cached_balance' => 85]);

        if (! $rewardsCustomer->orders()->exists()) {
            $demoProduct = Product::query()->firstOrFail();

            foreach (range(1, 3) as $number) {
                $request = $rewardsCustomer->orderRequests()->create([
                    'reference' => "REQ-REWARD{$number}",
                    'status' => 'product_finalized',
                    'submitted_at' => now()->subDays(10 - $number),
                ]);
                $order = $rewardsCustomer->orders()->create([
                    'reference' => "ORD-REWARD{$number}",
                    'order_request_id' => $request->id,
                    'product_id' => $demoProduct->id,
                    'product_title_snapshot' => $demoProduct->title,
                    'order_total' => 100,
                    'external_amount_due' => 100,
                    'payment_status' => 'paid',
                    'status' => 'completed',
                    'completed_at' => now()->subDays(8 - $number),
                ]);
                $order->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => 'completed',
                    'note' => 'Seeded completed demo order.',
                ]);
            }
        }

        WithdrawalRequest::updateOrCreate(
            ['reference' => 'WDR-DEMO001'],
            [
                'customer_id' => $rewardsCustomer->id,
                'amount' => 30,
                'status' => 'pending',
                'payment_details' => 'Demo payout account ending 001',
                'customer_note' => 'Please review this demo withdrawal.',
            ],
        );
    }
}
