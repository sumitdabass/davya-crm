<?php
namespace Tests\Unit\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\StudentFields\FieldRenderer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldRendererTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $type, array $opts = []): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 0]);
        return StudentField::create(array_merge([
            'section_id' => $section->id,
            'key' => $type . '_' . uniqid(),
            'label' => ucfirst($type),
            'type' => $type,
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ], $opts));
    }

    public function test_text_renders_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('text'));
        $this->assertInstanceOf(TextInput::class, $c);
    }

    public function test_textarea_renders_textarea(): void
    {
        $c = (new FieldRenderer())->render($this->field('textarea'));
        $this->assertInstanceOf(Textarea::class, $c);
    }

    public function test_number_renders_numeric_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('number'));
        $this->assertInstanceOf(TextInput::class, $c);
        $this->assertTrue($c->isNumeric());
    }

    public function test_date_renders_date_picker(): void
    {
        $c = (new FieldRenderer())->render($this->field('date'));
        $this->assertInstanceOf(DatePicker::class, $c);
    }

    public function test_email_renders_email_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('email'));
        $this->assertInstanceOf(TextInput::class, $c);
    }

    public function test_dropdown_renders_select_with_options(): void
    {
        $f = $this->field('dropdown', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]);
        $c = (new FieldRenderer())->render($f);
        $this->assertInstanceOf(Select::class, $c);
        $this->assertEquals(['a' => 'A', 'b' => 'B'], $c->getOptions());
    }

    public function test_checkbox_renders_toggle(): void
    {
        $c = (new FieldRenderer())->render($this->field('checkbox'));
        $this->assertInstanceOf(Toggle::class, $c);
    }

    public function test_multiselect_renders_select_multiple(): void
    {
        $f = $this->field('multiselect', ['options' => [['value' => 'a', 'label' => 'A']]]);
        $c = (new FieldRenderer())->render($f);
        $this->assertInstanceOf(Select::class, $c);
        $this->assertTrue($c->isMultiple());
    }

    public function test_required_field_marks_component_required(): void
    {
        $f = $this->field('text', ['is_required' => true]);
        $c = (new FieldRenderer())->render($f);
        $this->assertTrue($c->isRequired());
    }
}
