<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DebitCardTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected DebitCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->card = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);
        Passport::actingAs($this->user);
    }

    public function testCustomerCanSeeAListOfDebitCardTransactions()
    {
        // get /debit-card-transactions
        $this->card->debitCardTransactions()->createMany([[
            'amount' => 10000,
            'currency_code' => 'SGD'
        ], [
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]]);

        $response = $this->getJson("/api/debit-card-transactions?debit_card_id={$this->card->id}");

        $response
            ->assertOk()
            ->assertJson([
                ['amount' => 10000, 'currency_code' => 'SGD'],
                ['amount' => 15000, 'currency_code' => 'SGD']
            ]);
    }

    public function testCustomerCannotSeeAListOfDebitCardTransactionsOfOtherCustomerDebitCard()
    {
        // get /debit-card-transactions
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);
        $debitCard->debitCardTransactions()->createMany([[
            'amount' => 10000,
            'currency_code' => 'SGD'
        ], [
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]]);

        $response = $this->getJson("/api/debit-card-transactions?debit_card_id={$debitCard->id}");

        $response
            ->assertForbidden()
            ->assertJsonFragment(["message" => "This action is unauthorized."]);
    }

    public function testCustomerCanCreateADebitCardTransaction()
    {
        // post /debit-card-transactions
        $response = $this->postJson("/api/debit-card-transactions", [
            'debit_card_id' => $this->card->id,
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);

        $response
            ->assertCreated()
            ->assertJson(['amount' => 15000, 'currency_code' => 'SGD']);

        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $this->card->id,
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);
    }

    public function testCustomerCannotCreateADebitCardTransactionToOtherCustomerDebitCard()
    {
        // post /debit-card-transactions
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);

        $response = $this->postJson("/api/debit-card-transactions", [
            'debit_card_id' => $debitCard->id,
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $debitCard->id,
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);
    }

    public function testCustomerCanSeeADebitCardTransaction()
    {
        // get /debit-card-transactions/{debitCardTransaction}
        $transaction = $this->card->debitCardTransactions()->create([
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);

        $response = $this->getJson("/api/debit-card-transactions/{$transaction->id}");

        $response
            ->assertOk()
            ->assertJson(['amount' => 15000, 'currency_code' => 'SGD']);
    }

    public function testCustomerCannotSeeADebitCardTransactionAttachedToOtherCustomerDebitCard()
    {
        // get /debit-card-transactions/{debitCardTransaction}
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);
        $transaction = $debitCard->debitCardTransactions()->create([
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);

        $response = $this->getJson("/api/debit-card-transactions/{$transaction->id}");

        $response
            ->assertForbidden()
            ->assertJsonFragment(["message" => "This action is unauthorized."]);
    }

    // Extra bonus for extra tests :)
    public function testCustomerCannotSeeDebitCardTransactionsIfCardDoesNotExist()
    {
        $response = $this->getJson("/api/debit-card-transactions?debit_card_id=99");
        $response->assertForbidden();
    }

    public function testCustomerCannotCreateADebitCardTransactionWithInvalidData()
    {
        $response = $this->postJson("/api/debit-card-transactions", [
            'debit_card_id' => $this->card->id,
            'amount' => 1000
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonFragment(['currency_code' => ['The currency code field is required.']]);

        $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $this->card->id,
            'amount' => 15000,
            'currency_code' => 'SGD'
        ]);
    }

    public function testCustomerCannotSeeADebitCardTransactionIfItDoesntExist() {
        $response = $this->getJson("/api/debit-card-transactions/99");
        $response->assertNotFound();
    }
}
