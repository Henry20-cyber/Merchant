<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('businesses', function(Blueprint $t){$t->text('address')->nullable()->after('phone');$t->string('city',100)->nullable()->after('address');$t->string('state',100)->nullable()->after('city');}); }
 public function down(): void { Schema::table('businesses', function(Blueprint $t){$t->dropColumn(['address','city','state']);}); }
};
