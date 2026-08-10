<?php

namespace Tests\Feature\Admin;

use App\Enums\AgencyType;
use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Agency contact details and logo upload.
 */
class AgencyProfileTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Travel',
            'type' => AgencyType::Outlet->value,
            'contact_email' => 'hello@acme.test',
            'contact_phone' => '+63 917 555 0100',
            'address' => "12 Roxas Blvd\nManila",
        ], $overrides);
    }

    public function test_contact_details_are_saved(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload())
            ->assertRedirect(route('admin.agencies.index'));

        $agency = Agency::where('name', 'Acme Travel')->first();
        $this->assertSame('hello@acme.test', $agency->contact_email);
        $this->assertSame('+63 917 555 0100', $agency->contact_phone);
        $this->assertSame("12 Roxas Blvd\nManila", $agency->address);
    }

    public function test_contact_details_are_optional(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), [
                'name' => 'Bare Agency',
                'type' => AgencyType::Itp->value,
            ])
            ->assertRedirect(route('admin.agencies.index'));

        $agency = Agency::where('name', 'Bare Agency')->first();
        $this->assertNull($agency->contact_email);
        $this->assertNull($agency->logo_path);
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload(['contact_email' => 'not-an-email']))
            ->assertSessionHasErrors('contact_email');
    }

    // ---- Logo ------------------------------------------------------------

    public function test_a_logo_is_stored_and_the_path_recorded(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload([
                'logo' => UploadedFile::fake()->image('brand.png', 300, 300),
            ]))
            ->assertRedirect();

        $agency = Agency::where('name', 'Acme Travel')->first();

        $this->assertNotNull($agency->logo_path);
        Storage::disk('public')->assertExists($agency->logo_path);
        $this->assertStringContainsString('agency-logos/', $agency->logo_path);
    }

    public function test_the_stored_name_does_not_reuse_the_uploaded_filename(): void
    {
        // The original name is attacker-controlled and must never reach the filesystem.
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload([
                'logo' => UploadedFile::fake()->image('../../evil shell.png', 50, 50),
            ]))
            ->assertRedirect();

        $path = Agency::where('name', 'Acme Travel')->value('logo_path');

        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertMatchesRegularExpression('#^agency-logos/[A-Za-z0-9]{40}\.png$#', $path);
    }

    public function test_an_svg_is_rejected(): void
    {
        // SVG can carry script and would be served from our own origin.
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload([
                'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ]))
            ->assertSessionHasErrors('logo');

        $this->assertDatabaseMissing('agencies', ['name' => 'Acme Travel']);
    }

    public function test_a_non_image_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload([
                'logo' => UploadedFile::fake()->create('payload.php', 10, 'text/x-php'),
            ]))
            ->assertSessionHasErrors('logo');
    }

    public function test_an_oversized_logo_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), $this->payload([
                'logo' => UploadedFile::fake()->image('huge.png')->size(3000), // KB
            ]))
            ->assertSessionHasErrors('logo');
    }

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => $agency->name,
                'type' => $agency->type->value,
                'logo' => UploadedFile::fake()->image('first.png', 40, 40),
            ]))
            ->assertRedirect();

        $first = $agency->fresh()->logo_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => $agency->name,
                'type' => $agency->type->value,
                'logo' => UploadedFile::fake()->image('second.png', 40, 40),
            ]))
            ->assertRedirect();

        $second = $agency->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_logo_can_be_removed(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => $agency->name,
                'type' => $agency->type->value,
                'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
            ]))
            ->assertRedirect();

        $path = $agency->fresh()->logo_path;

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => $agency->name,
                'type' => $agency->type->value,
                'remove_logo' => 1,
            ]))
            ->assertRedirect();

        $this->assertNull($agency->fresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_updating_without_a_logo_field_keeps_the_existing_one(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => $agency->name,
                'type' => $agency->type->value,
                'logo' => UploadedFile::fake()->image('keep.png', 40, 40),
            ]))
            ->assertRedirect();

        $path = $agency->fresh()->logo_path;

        // A plain details edit must not silently drop the logo.
        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), $this->payload([
                'name' => 'Renamed',
                'type' => $agency->type->value,
            ]))
            ->assertRedirect();

        $this->assertSame($path, $agency->fresh()->logo_path);
        Storage::disk('public')->assertExists($path);
    }

    // ---- Display ---------------------------------------------------------

    public function test_the_show_page_renders_contact_details_and_the_logo(): void
    {
        $agency = Agency::factory()->create([
            'contact_email' => 'desk@acme.test',
            'contact_phone' => '+63 2 8888 1234',
            'address' => 'Makati City',
            'logo_path' => 'agency-logos/example.png',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertSee('desk@acme.test')
            ->assertSee('+63 2 8888 1234')
            ->assertSee('Makati City')
            ->assertSee(Storage::disk('public')->url('agency-logos/example.png'), escape: false);
    }

    public function test_initials_stand_in_when_there_is_no_logo(): void
    {
        $agency = Agency::factory()->create(['name' => 'Acme Travel', 'logo_path' => null]);

        $this->assertNull($agency->logoUrl());
        $this->assertSame('AT', $agency->initials());

        $this->actingAs($this->admin())
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertSee('AT');
    }

    public function test_the_form_accepts_file_uploads(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.agencies.create'))
            ->assertOk()
            ->assertSee('multipart/form-data', escape: false)
            ->assertSee('drag and drop');
    }
}
