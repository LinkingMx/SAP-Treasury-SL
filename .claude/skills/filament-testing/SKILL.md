# Filament v4 Testing Skill

## Activation Triggers
- Writing or modifying tests for Filament resources, tables, forms, actions, or notifications
- Working with files in `tests/Feature/Filament/` or `tests/Feature/` related to admin panel
- User mentions: "test filament", "test resource", "test table", "test action", "test notification", "test form"
- Debugging failing Filament tests
- Creating test coverage for Filament admin panel components

## Overview

All Filament components are Livewire components. Use Pest's Livewire plugin (`livewire()` function) or PHPUnit's `Livewire::test()`.

### What ARE Livewire Components (testable with `livewire()`)
- Pages in a panel (including resource page classes in `Pages/` directory)
- Relation managers
- Widgets

### What are NOT Livewire Components
- Resource classes themselves
- Schema components (fields, sections)
- Actions

---

## Authentication Setup

```php
// Pest beforeEach
use App\Models\User;

beforeEach(function () {
    $user = User::factory()->create();
    actingAs($user);
});
```

For multi-panel testing:
```php
use Filament\Facades\Filament;
Filament::setCurrentPanel('admin'); // Panel ID
```

For multi-tenant:
```php
use Filament\Facades\Filament;
$team = Team::factory()->create();
Filament::setTenant($team);
Filament::bootCurrentPanel();
```

---

## Testing Resources

### List Page

```php
use App\Filament\Resources\UserResource\Pages\ListUsers;

it('can load the page', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users);
});
```

**Search:**
```php
it('can search users by name', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords($users)
        ->searchTable($users->first()->name)
        ->assertCanSeeTableRecords($users->take(1))
        ->assertCanNotSeeTableRecords($users->skip(1));
});
```

**Sort:**
```php
it('can sort users by name', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($users->sortBy('name'), inOrder: true)
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords($users->sortByDesc('name'), inOrder: true);
});
```

**Filter:**
```php
it('can filter users by locale', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->filterTable('locale', $users->first()->locale)
        ->assertCanSeeTableRecords($users->where('locale', $users->first()->locale))
        ->assertCanNotSeeTableRecords($users->where('locale', '!=', $users->first()->locale));
});
```

**Bulk Actions:**
```php
use Filament\Actions\Testing\TestAction;
use Filament\Actions\DeleteBulkAction;

it('can bulk delete users', function () {
    $users = User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords($users)
        ->selectTableRecords($users)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($users);
});
```

### Create Page

```php
use App\Filament\Resources\UserResource\Pages\CreateUser;

it('can load the page', function () {
    livewire(CreateUser::class)
        ->assertOk();
});

it('can create a user', function () {
    $newData = User::factory()->make();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => $newData->name,
            'email' => $newData->email,
        ])
        ->call('create')
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseHas(User::class, [
        'name' => $newData->name,
        'email' => $newData->email,
    ]);
});
```

**Validation with Datasets:**
```php
it('validates the form data', function (array $data, array $errors) {
    $newData = User::factory()->make();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => $newData->name,
            'email' => $newData->email,
            ...$data,
        ])
        ->call('create')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`name` is required' => [['name' => null], ['name' => 'required']],
    '`name` is max 255 characters' => [['name' => Str::random(256)], ['name' => 'max']],
    '`email` is required' => [['email' => null], ['email' => 'required']],
    '`email` is a valid email' => [['email' => Str::random()], ['email' => 'email']],
]);
```

### Edit Page

```php
use App\Filament\Resources\UserResource\Pages\EditUser;

it('can load the page', function () {
    $user = User::factory()->create();

    livewire(EditUser::class, ['record' => $user->id])
        ->assertOk()
        ->assertSchemaStateSet([
            'name' => $user->name,
            'email' => $user->email,
        ]);
});

it('can update a user', function () {
    $user = User::factory()->create();
    $newData = User::factory()->make();

    livewire(EditUser::class, ['record' => $user->id])
        ->fillForm([
            'name' => $newData->name,
            'email' => $newData->email,
        ])
        ->call('save')
        ->assertNotified();

    assertDatabaseHas(User::class, [
        'id' => $user->id,
        'name' => $newData->name,
        'email' => $newData->email,
    ]);
});
```

**Delete from Edit Page:**
```php
use Filament\Actions\DeleteAction;

it('can delete a user', function () {
    $user = User::factory()->create();

    livewire(EditUser::class, ['record' => $user->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    assertDatabaseMissing($user);
});
```

### View Page

```php
use App\Filament\Resources\UserResource\Pages\ViewUser;

it('can load the page', function () {
    $user = User::factory()->create();

    livewire(ViewUser::class, ['record' => $user->id])
        ->assertOk()
        ->assertSchemaStateSet([
            'name' => $user->name,
            'email' => $user->email,
        ]);
});
```

### Relation Managers

```php
use App\Filament\Resources\UserResource\RelationManagers\PostsRelationManager;
use App\Filament\Resources\UserResource\Pages\EditUser;

// Check it renders on the page
it('can load the relation manager', function () {
    $user = User::factory()->create();

    livewire(EditUser::class, ['record' => $user->id])
        ->assertSeeLivewire(PostsRelationManager::class);
});

// Test the relation manager directly
it('can list posts', function () {
    $user = User::factory()->has(Post::factory()->count(5))->create();

    livewire(PostsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($user->posts);
});
```

### Custom getFormActions()

```php
use Filament\Actions\Testing\TestAction;

it('can create and verify email', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])
        ->callAction(
            TestAction::make('createAndVerifyEmail')
                ->schemaComponent('form-actions', schema: 'content')
        );
});
```

---

## Testing Tables

### Rendering & Records

```php
// Basic render
livewire(ListPosts::class)->assertSuccessful();

// Check visible records
livewire(ListPosts::class)
    ->assertCanSeeTableRecords($posts)
    ->assertCanNotSeeTableRecords($trashedPosts)
    ->assertCountTableRecords(4);

// For deferred loading tables
livewire(ListPosts::class)
    ->loadTable()
    ->assertCanSeeTableRecords($posts);

// Pagination: switch to page 2
livewire(ListPosts::class)->call('gotoPage', 2);
```

### Columns

```php
// Render check
->assertCanRenderTableColumn('title')
->assertCanNotRenderTableColumn('comments')

// Search (global)
->searchTable($title)

// Search (individual column)
->searchTableColumns(['title' => $title])

// Sort
->sortTable('title')
->assertCanSeeTableRecords($sortedAsc, inOrder: true)
->sortTable('title', 'desc')
->assertCanSeeTableRecords($sortedDesc, inOrder: true)

// State
->assertTableColumnStateSet('author.name', $post->author->name, record: $post)
->assertTableColumnStateNotSet('author.name', 'Anonymous', record: $post)

// Formatted state
->assertTableColumnFormattedStateSet('author.name', 'Smith, John', record: $post)
->assertTableColumnFormattedStateNotSet('author.name', $post->author->name, record: $post)

// Existence
->assertTableColumnExists('author')
->assertTableColumnExists('author', function (TextColumn $column): bool {
    return $column->getDescriptionBelow() === $post->subtitle;
}, $post)

// Visibility
->assertTableColumnVisible('created_at')
->assertTableColumnHidden('author')

// Description
->assertTableColumnHasDescription('author', 'Description text', $post, 'above')
->assertTableColumnHasDescription('author', 'Description text', $post) // below default
->assertTableColumnDoesNotHaveDescription('author', 'Wrong text', $post)

// Extra attributes
->assertTableColumnHasExtraAttributes('author', ['class' => 'text-danger-500'], $post)
->assertTableColumnDoesNotHaveExtraAttributes('author', ['class' => 'text-primary-500'], $post)

// Select column options
->assertTableSelectColumnHasOptions('status', ['unpublished' => 'Unpublished', 'published' => 'Published'], $post)
->assertTableSelectColumnDoesNotHaveOptions('status', ['archived' => 'Archived'], $post)
```

### Filters

```php
// Simple filter (toggle on)
->filterTable('is_published')

// SelectFilter / TernaryFilter (with value)
->filterTable('author_id', $authorId)

// Reset all filters
->resetTableFilters()

// Remove single filter
->removeTableFilter('is_published')

// Remove all filters
->removeTableFilters()

// Filter existence
->assertTableFilterExists('author')
->assertTableFilterExists('author', function (SelectFilter $filter): bool {
    return $filter->getLabel() === 'Select author';
})

// Filter visibility
->assertTableFilterVisible('created_at')
->assertTableFilterHidden('author')
```

### Summaries

```php
->assertTableColumnSummarySet('rating', 'average', $posts->avg('rating'))

// Current page only
->assertTableColumnSummarySet('rating', 'average', $value, isCurrentPaginationPageOnly: true)

// Range summarizer
->assertTableColumnSummarySet('rating', 'range', [$posts->min('rating'), $posts->max('rating')])
```

### Toggleable Columns

```php
->toggleAllTableColumns()      // Toggle all ON
->toggleAllTableColumns(false) // Toggle all OFF
```

---

## Testing Schemas (Forms & Infolists)

### Fill Form

```php
// Single form
->fillForm(['title' => fake()->sentence()])

// Named form (multiple schemas)
->fillForm([...], 'createPostForm')
```

### Assert State

```php
// Check state
->assertSchemaStateSet(['slug' => Str::slug($title)])

// Named schema
->assertSchemaStateSet([...], 'createPostForm')

// With function for additional assertions
->assertSchemaStateSet(function (array $state): array {
    expect($state['slug'])->not->toContain(' ');
    return ['slug' => Str::slug($title)];
})
```

### Validation

```php
// Has errors
->assertHasFormErrors(['title' => 'required'])

// No errors
->assertHasNoFormErrors()

// Named form
->assertHasFormErrors(['title' => 'required'], 'createPostForm')
->assertHasNoFormErrors([], 'createPostForm')
```

### Form Existence

```php
->assertFormExists()
->assertFormExists('createPostForm') // named form
```

### Field Existence

```php
->assertFormFieldExists('title')
->assertFormFieldDoesNotExist('no-such-field')

// With truth test
->assertFormFieldExists('title', function (TextInput $field): bool {
    return $field->isDisabled();
})

// Named form
->assertFormFieldExists('title', 'createPostForm')
```

### Field Visibility

```php
->assertFormFieldVisible('title')
->assertFormFieldHidden('title')

// Named form
->assertFormFieldHidden('title', 'createPostForm')
```

### Field Disabled State

```php
->assertFormFieldEnabled('title')
->assertFormFieldDisabled('title')

// Named form
->assertFormFieldEnabled('title', 'createPostForm')
```

### Schema Components (Sections, etc.)

Requires `->key()` on the component:
```php
Section::make('Comments')->key('comments-section')->schema([...])
```

```php
->assertSchemaComponentExists('comments-section')
->assertSchemaComponentDoesNotExist('no-such-section')

// With truth test
->assertSchemaComponentExists('comments-section', checkComponentUsing: function (Section $component): bool {
    return $component->getHeading() === 'Comments';
})

// Visibility
->assertSchemaComponentVisible('comments-section')
->assertSchemaComponentHidden('comments-section')
```

### Repeaters

```php
use Filament\Forms\Components\Repeater;

$undoRepeaterFake = Repeater::fake(); // Replace UUIDs with numeric keys

livewire(EditPost::class, ['record' => $post])
    ->assertSchemaStateSet([
        'quotes' => [
            ['content' => 'First quote'],
            ['content' => 'Second quote'],
        ],
    ]);

$undoRepeaterFake(); // Always undo at end
```

### Builders

```php
use Filament\Forms\Components\Builder;

$undoBuilderFake = Builder::fake();

livewire(EditPost::class, ['record' => $post])
    ->assertSchemaStateSet([
        'content' => [
            ['type' => 'heading', 'data' => ['content' => 'Hello!', 'level' => 'h1']],
            ['type' => 'paragraph', 'data' => ['content' => 'Test.']],
        ],
    ]);

$undoBuilderFake();
```

### Wizards

```php
->goToNextWizardStep()
->goToPreviousWizardStep()
->goToWizardStep(2)
->assertWizardCurrentStep(2)

// Named schema
->goToNextWizardStep(schema: 'fooForm')
->assertHasFormErrors(['title'], schema: 'fooForm')
```

---

## Testing Actions

### Basic Action Call

```php
it('can send invoice', function () {
    $invoice = Invoice::factory()->create();

    livewire(EditInvoice::class, ['invoice' => $invoice])
        ->callAction('send');

    expect($invoice->refresh())->isSent()->toBeTrue();
});
```

### Table Actions (with TestAction)

```php
use Filament\Actions\Testing\TestAction;

// Row action (pass record)
->callAction(TestAction::make('send')->table($invoice))
->assertActionVisible(TestAction::make('send')->table($invoice))
->assertActionExists(TestAction::make('send')->table($invoice))

// Header action (no record)
->callAction(TestAction::make('create')->table())
->assertActionVisible(TestAction::make('create')->table())

// Bulk action
->selectTableRecords($invoices->pluck('id')->toArray())
->callAction(TestAction::make('send')->table()->bulk())
```

### Schema Actions

```php
// Action on a schema component (e.g., infolist entry)
->callAction(TestAction::make('send')->schemaComponent('customer_id'))

// Nested: action inside another action's modal form
->callAction([
    TestAction::make('view')->table($invoice),
    TestAction::make('send')->schemaComponent('customer.name'),
])
```

### Action Modal Forms

```php
// Pass data to action
->callAction('send', data: ['email' => fake()->email()])
->assertHasNoFormErrors()

// Mount without calling (to inspect modal)
->mountAction('send')
->fillForm(['email' => fake()->email()])

// Validation errors in modal
->callAction('send', data: ['email' => Str::random()])
->assertHasFormErrors(['email' => ['email']])

// Pre-filled data check
->mountAction('send')
->assertSchemaStateSet(['email' => $recipientEmail])
->callMountedAction()
```

### Action Modal Content

```php
->mountAction('send')
->assertMountedActionModalSee($recipientEmail)
->assertMountedActionModalDontSee('wrong text')
->assertMountedActionModalSeeHtml('<strong>bold</strong>')
->assertMountedActionModalDontSeeHtml('<em>italic</em>')
```

### Action Existence

```php
->assertActionExists('send')
->assertActionDoesNotExist('unsend')

// With truth test
->assertActionExists('send', function (Action $action): bool {
    return $action->getModalDescription() === 'Expected description';
})
```

### Action Visibility

```php
->assertActionVisible('print')
->assertActionHidden('send')
```

### Action Enabled/Disabled

```php
->assertActionEnabled('print')
->assertActionDisabled('send')
```

### Action Order

```php
->assertActionsExistInOrder(['send', 'export'])
```

### Action Label

```php
->assertActionHasLabel('send', 'Email Invoice')
->assertActionDoesNotHaveLabel('send', 'Send')
```

### Action Icon

```php
->assertActionHasIcon('send', 'envelope-open')
->assertActionDoesNotHaveIcon('send', 'envelope')
```

### Action Color

```php
->assertActionHasColor('delete', 'danger')
->assertActionDoesNotHaveColor('print', 'danger')
```

### Action URL

```php
->assertActionHasUrl('filament', 'https://filamentphp.com/')
->assertActionDoesNotHaveUrl('filament', 'https://github.com/...')
->assertActionShouldOpenUrlInNewTab('filament')
->assertActionShouldNotOpenUrlInNewTab('github')
```

### Action Arguments

```php
->callAction(TestAction::make('send')->arguments(['invoice' => $invoice->getKey()]))
```

### Action Halted

```php
->callAction('send')
->assertActionHalted('send')
```

### Using Action Class Names

```php
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;

->callAction(CreateAction::class)
->callAction(DeleteAction::class)
->callAction(EditAction::class)
```

---

## Testing Notifications

### Basic Notification Assertions

```php
// Was any notification sent?
->assertNotified()

// Was a specific notification title sent?
->assertNotified('Unable to create post')

// Was an exact notification sent?
use Filament\Notifications\Notification;

->assertNotified(
    Notification::make()
        ->danger()
        ->title('Unable to create post')
        ->body('Something went wrong.')
)

// No notification sent
->assertNotNotified()
->assertNotNotified('Unable to create post')
->assertNotNotified(
    Notification::make()
        ->danger()
        ->title('Unable to create post')
        ->body('Something went wrong.')
)
```

### Static Notification Assertion

```php
use Filament\Notifications\Notification;

Notification::assertNotified();
```

### Function Import

```php
use function Filament\Notifications\Testing\assertNotified;

assertNotified();
```

---

## Quick Reference: All Assert Methods

### Table Assertions
| Method | Purpose |
|--------|---------|
| `assertCanSeeTableRecords($records, inOrder: false)` | Records visible in table |
| `assertCanNotSeeTableRecords($records)` | Records NOT visible |
| `assertCountTableRecords($count)` | Record count matches |
| `assertCanRenderTableColumn($name)` | Column renders |
| `assertCanNotRenderTableColumn($name)` | Column does NOT render |
| `assertTableColumnStateSet($name, $state, record:)` | Column state matches |
| `assertTableColumnStateNotSet($name, $state, record:)` | Column state doesn't match |
| `assertTableColumnFormattedStateSet($name, $state, record:)` | Formatted state matches |
| `assertTableColumnFormattedStateNotSet($name, $state, record:)` | Formatted state doesn't match |
| `assertTableColumnExists($name, ?callback, ?record)` | Column exists |
| `assertTableColumnVisible($name)` | Column visible |
| `assertTableColumnHidden($name)` | Column hidden |
| `assertTableColumnHasDescription($name, $desc, $record, $position)` | Description matches |
| `assertTableColumnDoesNotHaveDescription(...)` | Description doesn't match |
| `assertTableColumnHasExtraAttributes($name, $attrs, $record)` | Extra attrs match |
| `assertTableColumnDoesNotHaveExtraAttributes(...)` | Extra attrs don't match |
| `assertTableSelectColumnHasOptions($name, $opts, $record)` | Select options match |
| `assertTableSelectColumnDoesNotHaveOptions(...)` | Select options don't match |
| `assertTableFilterExists($name, ?callback)` | Filter exists |
| `assertTableFilterVisible($name)` | Filter visible |
| `assertTableFilterHidden($name)` | Filter hidden |
| `assertTableColumnSummarySet($col, $id, $value, ...)` | Summary matches |

### Table Interaction Methods
| Method | Purpose |
|--------|---------|
| `searchTable($query)` | Search globally |
| `searchTableColumns(['col' => $query])` | Search individual columns |
| `sortTable($column, $direction)` | Sort table |
| `filterTable($name, $value)` | Apply filter |
| `resetTableFilters()` | Reset all filters |
| `removeTableFilter($name)` | Remove single filter |
| `removeTableFilters()` | Remove all filters |
| `selectTableRecords($records)` | Select records for bulk |
| `loadTable()` | Load deferred table |
| `toggleAllTableColumns($on)` | Toggle all columns |

### Schema/Form Assertions
| Method | Purpose |
|--------|---------|
| `assertSchemaStateSet($state, ?schema)` | Schema state matches |
| `assertHasFormErrors($errors, ?form)` | Validation errors exist |
| `assertHasNoFormErrors(?errors, ?form)` | No validation errors |
| `assertFormExists(?form)` | Form exists |
| `assertFormFieldExists($name, ?callback)` | Field exists |
| `assertFormFieldDoesNotExist($name)` | Field doesn't exist |
| `assertFormFieldVisible($name, ?form)` | Field visible |
| `assertFormFieldHidden($name, ?form)` | Field hidden |
| `assertFormFieldEnabled($name, ?form)` | Field enabled |
| `assertFormFieldDisabled($name, ?form)` | Field disabled |
| `assertSchemaComponentExists($key, ?checkCallback)` | Component exists |
| `assertSchemaComponentDoesNotExist($key)` | Component doesn't exist |
| `assertSchemaComponentVisible($key, ?schema)` | Component visible |
| `assertSchemaComponentHidden($key, ?schema)` | Component hidden |

### Action Assertions
| Method | Purpose |
|--------|---------|
| `assertActionExists($name, ?callback)` | Action exists |
| `assertActionDoesNotExist($name)` | Action doesn't exist |
| `assertActionVisible($name)` | Action visible |
| `assertActionHidden($name)` | Action hidden |
| `assertActionEnabled($name)` | Action enabled |
| `assertActionDisabled($name)` | Action disabled |
| `assertActionsExistInOrder($names)` | Actions in order |
| `assertActionHasLabel($name, $label)` | Label matches |
| `assertActionDoesNotHaveLabel($name, $label)` | Label doesn't match |
| `assertActionHasIcon($name, $icon)` | Icon matches |
| `assertActionDoesNotHaveIcon($name, $icon)` | Icon doesn't match |
| `assertActionHasColor($name, $color)` | Color matches |
| `assertActionDoesNotHaveColor($name, $color)` | Color doesn't match |
| `assertActionHasUrl($name, $url)` | URL matches |
| `assertActionDoesNotHaveUrl($name, $url)` | URL doesn't match |
| `assertActionShouldOpenUrlInNewTab($name)` | Opens in new tab |
| `assertActionShouldNotOpenUrlInNewTab($name)` | Doesn't open in new tab |
| `assertActionHalted($name)` | Action was halted |
| `assertMountedActionModalSee($text)` | Modal contains text |
| `assertMountedActionModalDontSee($text)` | Modal doesn't contain text |
| `assertMountedActionModalSeeHtml($html)` | Modal contains HTML |
| `assertMountedActionModalDontSeeHtml($html)` | Modal doesn't contain HTML |

### Notification Assertions
| Method | Purpose |
|--------|---------|
| `assertNotified(?$title_or_notification)` | Notification sent |
| `assertNotNotified(?$title_or_notification)` | Notification NOT sent |

### General Assertions
| Method | Purpose |
|--------|---------|
| `assertOk()` | HTTP 200 response |
| `assertSuccessful()` | HTTP 2xx response |
| `assertNotified()` | Any notification sent |
| `assertNotNotified()` | No notification sent |
| `assertRedirect()` | Was redirected |
| `assertNoRedirect()` | Was NOT redirected |

---

## Best Practices

1. **Always authenticate** in `beforeEach()` before testing Filament pages
2. **Use `it()` syntax** for test descriptions (Pest convention)
3. **Use datasets** for repetitive validation testing
4. **Use `assertDatabaseHas`/`assertDatabaseMissing`** to verify DB state after create/edit/delete
5. **Use `TestAction::make()`** for table actions, bulk actions, and schema component actions
6. **Use `Repeater::fake()` / `Builder::fake()`** when testing repeaters or builders (always undo after)
7. **Pass `record` parameter** to edit/view page tests: `livewire(EditPage::class, ['record' => $model->id])`
8. **Relation managers require** `ownerRecord` and `pageClass` parameters
9. **Create page calls** `->call('create')`, **Edit page calls** `->call('save')`
10. **For deferred loading tables**, call `->loadTable()` before asserting records
