<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected DebitCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);

        $this->card = $this->user->debitCards()->create(
            [
                'type' => 'debit_card',
                'number' => rand(1000000000000000, 9999999999999999),
                'expiration_date' => Carbon::now()->addYear(),
            ]
        );
    }

    public function testCustomerCanSeeAListOfDebitCards()
    {
        // get /debit-cards
        $response = $this->getJson("/api/debit-cards");
        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([
                '*' => [
                    'id', 'number', 'type', 'expiration_date', 'is_active'
                ]
            ]);
    }

    public function testCustomerCannotSeeAListOfDebitCardsOfOtherCustomers()
    {
        // get /debit-cards
        $differentUser = User::factory()->create();
        $differentUser->debitCards()->create(
            [
                'type' => 'credit_card',
                'number' => rand(1000000000000000, 9999999999999999),
                'expiration_date' => Carbon::now()->addYear()
            ]
        );

        $response = $this->getJson("/api/debit-cards");
        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'id' => $this->card->id,
                'number' => $this->card->number,
                "type" => "debit_card",
                "is_active" => true]);
    }

    public function testCustomerCanCreateADebitCard()
    {
        // post /debit-cards
        $response = $this->postJson('/api/debit-cards', ['type' => 'credit_card']);
        $response
            ->assertCreated()
            ->assertJsonFragment(['type' => 'credit_card']);
        $this->assertDatabaseCount('debit_cards', 2);
        $response->assertCreated();
    }

    public function testCustomerCanSeeASingleDebitCardDetails()
    {
        // get api/debit-cards/{debitCard}
        $response = $this->getJson("/api/debit-cards/{$this->card->id}");
        $response
            ->assertOk()
            ->assertJsonFragment(['id' => 1, 'number' => $this->card->number]);
    }

    public function testCustomerCannotSeeASingleDebitCardDetails()
    {
        // get api/debit-cards/{debitCard}
        $response = $this->getJson("/api/debit-cards/999");
        $response->assertNotFound();
    }

    public function testCustomerCanActivateADebitCard()
    {
        // put api/debit-cards/{debitCard}
        $card = $this->user->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear(),
            'disabled_at' => Carbon::now()]);

        $this->assertFalse($card->getAttribute('is_active'));

        $response = $this->putJson("/api/debit-cards/{$card->id}", ['is_active' => true]);
        $response
            ->assertOk()
            ->assertJsonFragment(['id' => 2]);

        $this->assertDatabaseHas('debit_cards', [
            'id' => $card->id,
            'disabled_at' => null
        ]);
    }

    public function testCustomerCanDeactivateADebitCard()
    {
        // put api/debit-cards/{debitCard}
        $this->assertTrue($this->card->getAttribute('is_active'));

        $response = $this->putJson("/api/debit-cards/{$this->card->id}", ['is_active' => false]);
        $response
            ->assertOk()
            ->assertJsonFragment(['id' => 1]);

        $this->assertDatabaseMissing('debit_cards', [
            'id' => $this->card->id,
            'disabled_at' => null
        ]);
    }

    public function testCustomerCannotUpdateADebitCardWithWrongValidation()
    {
        // put api/debit-cards/{debitCard}
        $response = $this->putJson("/api/debit-cards/{$this->card->id}");
        $response
            ->assertUnprocessable()
            ->assertJsonFragment(['is_active' => ['The is active field is required.']]);
    }

    public function testCustomerCanDeleteADebitCard()
    {
        // delete api/debit-cards/{debitCard}
        $this->assertNotSoftDeleted($this->card);

        $response = $this->deleteJson("/api/debit-cards/{$this->card->id}");
        $response->assertNoContent();

        $this->assertSoftDeleted($this->card);
    }

    public function testCustomerCannotDeleteADebitCardWithTransaction()
    {
        // delete api/debit-cards/{debitCard}
        $this->card->debitCardTransactions()->create([
            'amount' => 1000,
            'currency_code' => 'IDR'
        ]);

        $this->assertDatabaseCount('debit_card_transactions', 1);
        $response = $this->deleteJson("/api/debit-cards/{$this->card->id}");
        $response->assertForbidden();

        $this->assertNotSoftDeleted($this->card);
    }

    // Extra bonus for extra tests :)
    public function testCustomerCannotGetASingleDebitCardOfAnotherUser()
    {
        // get api/debit-cards/{debitCard}
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);

        $response = $this->getJson("/api/debit-cards/{$debitCard->id}");
        $response->assertForbidden();
    }

    public function testCustomerCannotCreateADebitCardWithWrongValidation()
    {
        // put api/debit-cards/{debitCard}
        $response = $this->postJson('/api/debit-cards');
        $response->assertUnprocessable();
    }

    public function testCustomerCannotUpdateADebitCardOfAnotherUser()
    {
        // put api/debit-cards/{debitCard}
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);
        $this->assertTrue($debitCard->getAttribute('is_active'));

        $response = $this->putJson("/api/debit-cards/{$debitCard->id}", ['is_active' => false]);
        $response->assertForbidden();

        $this->assertDatabaseMissing('debit_cards', [
            'id' => $debitCard->id,
            'is_active' => false
        ]);
    }

    public function testCustomerCannotDeleteADebitCardOfAnotherUser()
    {
        // put api/debit-cards/{debitCard}
        $differentUser = User::factory()->create();
        $debitCard = $differentUser->debitCards()->create([
            'type' => 'credit_card',
            'number' => rand(1000000000000000, 9999999999999999),
            'expiration_date' => Carbon::now()->addYear()
        ]);
        $this->assertTrue($debitCard->getAttribute('is_active'));

        $response = $this->deleteJson("/api/debit-cards/{$debitCard->id}");
        $response->assertForbidden();
    }
}
