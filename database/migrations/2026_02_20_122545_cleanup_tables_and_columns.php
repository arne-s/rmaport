<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('brands');
        Schema::dropIfExists('company_sliders');
        Schema::dropIfExists('faq_categories');
        Schema::dropIfExists('faq_questions');
        Schema::dropIfExists('filter_attribute_values');
        Schema::dropIfExists('filter_attributes');
        Schema::dropIfExists('form_entries');
        Schema::dropIfExists('margin_defaults');
        Schema::dropIfExists('margins');
        Schema::dropIfExists('minuba_syncs');
        Schema::dropIfExists('news');
        Schema::dropIfExists('news_types');
        Schema::dropIfExists('page_types');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('partners_companies');
        Schema::dropIfExists('price_table_log');
        Schema::dropIfExists('price_table_values');
        Schema::dropIfExists('price_tables');
        Schema::dropIfExists('product_attributes_custom_pivot');
        Schema::dropIfExists('product_attributes_options_pivot');
        Schema::dropIfExists('product_attributes_pivot');
        Schema::dropIfExists('product_bundle_step_attribute_values');
        Schema::dropIfExists('product_bundle_step_attributes');
        Schema::dropIfExists('product_bundle_step_types');
        Schema::dropIfExists('product_bundle_steps');
        Schema::dropIfExists('product_bundles');
        Schema::dropIfExists('product_rule_actions');
        Schema::dropIfExists('product_rule_triggers');
        Schema::dropIfExists('product_rules');
        Schema::dropIfExists('product_visualisations');
        Schema::dropIfExists('ral_custom_colors');
        Schema::dropIfExists('slider_usps');
        Schema::dropIfExists('subsites');
        Schema::dropIfExists('subsites_products');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
