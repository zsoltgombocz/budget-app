<?php

use App\Actions\Budget\SaveLoan;
use App\Actions\Budget\SavePlanLine;
use App\Models\User;
use Database\Seeders\CategoryTemplateSeeder;
use Livewire\Livewire;

/**
 * Asserts the errors are human Hungarian sentences: no field identifier such as
 * "message", "form.calcMode" or "loanId", and no untranslated "validation." key.
 *
 * @param  array<string, string>|array<string, array<int, string>>  $errors
 * @param  list<string>  $fields
 */
function expectHumanErrors(array $errors, array $fields): void
{
    expect(array_keys($errors))->toContain(...$fields);

    foreach ($fields as $field) {
        $message = is_array($errors[$field]) ? $errors[$field][0] : $errors[$field];
        $bareField = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;

        expect($message)
            ->not->toContain('validation.')
            ->not->toContain('form.')
            ->not->toMatch('/\b'.preg_quote($bareField, '/').'\b/')
            ->not->toMatch('/\b[a-z]+[A-Z][a-zA-Z]*\b/')
            ->not->toContain('field');
    }
}

beforeEach(function (): void {
    app()->setLocale('hu');
});

it('names the feedback message in Hungarian', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));

    $component = Livewire::test('pages::settings.feedback', ['kind' => 'idea'])->call('send');

    expectHumanErrors($component->errors()->toArray(), ['message']);
    expect($component->errors()->first('message'))->toBe('Írd meg az üzeneted.');
});

it('names the sign-in fields in Hungarian', function (): void {
    $component = Livewire::test('auth.magic-link-form')->call('send');
    expectHumanErrors($component->errors()->toArray(), ['email']);

    $component = Livewire::test('auth.magic-link-form')->set('sentTo', 'someone@example.com')->call('verify');
    expectHumanErrors($component->errors()->toArray(), ['code']);
});

it('asks for the income in a natural sentence during onboarding', function (): void {
    $this->seed(CategoryTemplateSeeder::class);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::onboarding')->set('income', '')->call('next');

    expectHumanErrors($component->errors()->toArray(), ['income']);
    expect($component->errors()->first('income'))->toBe('Add meg a havi nettó bevételed.');

    $component = Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', '')->call('next');

    expectHumanErrors($component->errors()->toArray(), ['periodMode']);
});

it('names the plan line fields in Hungarian', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));

    $result = Livewire::test('pages::plan')->instance()->saveLine(['name' => '', 'type' => '', 'origCurrency' => 'EUR'], resolve(SavePlanLine::class));

    expect($result['ok'])->toBeFalse();
    expectHumanErrors($result['errors'], ['form.name', 'form.type']);
});

it('names the pocket and loan fields in Hungarian', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));
    $page = Livewire::test('pages::pockets')->instance();

    $pocket = $page->savePocket(['name' => '', 'amounts' => ['target' => 'abc']]);
    expectHumanErrors($pocket['errors'], ['name']);

    $loan = $page->saveLoan(['name' => '', 'amounts' => ['thm' => '500', 'months' => '0']], resolve(SaveLoan::class));
    expectHumanErrors($loan['errors'], ['name', 'principal', 'installment', 'months', 'prepayMode']);
});

it('names the settings fields in Hungarian', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));

    $appearance = Livewire::test('pages::settings.appearance')->set('locale', '');
    expectHumanErrors($appearance->errors()->toArray(), ['locale']);

    $budget = Livewire::test('pages::settings.budget')->set('periodMode', 'payday')->set('paydayDay')->call('save');
    expectHumanErrors($budget->errors()->toArray(), ['paydayDay']);

    $notifications = Livewire::test('pages::settings.notifications')->set('reminderTime', '');
    expectHumanErrors($notifications->errors()->toArray(), ['reminderTime']);
});

it('has a field name for every validated field in English too', function (): void {
    $hungarian = require lang_path('hu/validation.php');
    $english = require lang_path('en/validation.php');

    expect(array_keys($english['attributes']))->toBe(array_keys($hungarian['attributes']))
        ->and(array_keys($english['custom']))->toBe(array_keys($hungarian['custom']));
});
