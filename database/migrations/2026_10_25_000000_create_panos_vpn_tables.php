<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panos_vpn_firewalls')) {
            Schema::create('panos_vpn_firewalls', function (Blueprint $table): void {
                $table->unsignedInteger('device_id')->primary();
                $table->string('hostname');
                $table->string('display_name');
                $table->unsignedInteger('last_polled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->text('last_warning')->nullable();
                $table->unsignedInteger('poll_ms')->nullable();
            });
        }

        if (! Schema::hasTable('panos_vpn_tunnels')) {
            Schema::create('panos_vpn_tunnels', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('device_id');
                $table->string('pan_id', 32)->default('');
                $table->string('name', 191);
                $table->string('gateway', 191)->nullable();
                $table->string('local_ip', 64)->nullable();
                $table->string('peer_ip', 64)->nullable();
                $table->string('inner_if', 64)->nullable();
                $table->string('outer_if', 64)->nullable();
                $table->string('state', 32);
                $table->string('monitor', 16)->nullable();
                $table->string('monitor_status', 32)->nullable();
                $table->string('protocol', 32)->nullable();
                $table->string('encryption', 64)->nullable();
                $table->string('auth_hash', 64)->nullable();
                $table->unsignedBigInteger('encap_bytes')->nullable();
                $table->unsignedBigInteger('decap_bytes')->nullable();
                $table->unsignedBigInteger('bps_out')->nullable();
                $table->unsignedBigInteger('bps_in')->nullable();
                $table->string('bandwidth_scope', 16)->nullable();
                $table->unsignedInteger('last_seen_at')->nullable();
                $table->unique(['device_id', 'name']);
            });
        }

        if (! Schema::hasTable('panos_vpn_tunnel_samples')) {
            Schema::create('panos_vpn_tunnel_samples', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('tunnel_row');
                $table->unsignedInteger('polled_at');
                $table->unsignedBigInteger('bps_in')->nullable();
                $table->unsignedBigInteger('bps_out')->nullable();
                $table->index(['tunnel_row', 'polled_at']);
            });
        }

        if (! Schema::hasTable('panos_vpn_gateways')) {
            Schema::create('panos_vpn_gateways', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('device_id');
                $table->string('name', 191);
                $table->unsignedInteger('current_users')->default(0);
                $table->unsignedInteger('previous_users')->nullable();
                $table->unsignedInteger('max_users')->nullable();
                $table->unsignedInteger('last_seen_at')->nullable();
                $table->unique(['device_id', 'name']);
            });
        }

        if (! Schema::hasTable('panos_vpn_gateway_samples')) {
            Schema::create('panos_vpn_gateway_samples', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('gateway_row');
                $table->unsignedInteger('polled_at');
                $table->unsignedInteger('current_users');
                $table->index(['gateway_row', 'polled_at']);
            });
        }

        if (! Schema::hasTable('panos_vpn_users')) {
            Schema::create('panos_vpn_users', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('device_id');
                $table->string('gateway_name', 191);
                $table->string('username', 191);
                $table->string('domain', 191)->nullable();
                $table->string('computer', 191)->nullable();
                $table->string('client', 191)->nullable();
                $table->string('virtual_ip', 64)->nullable();
                $table->string('public_ip', 64)->nullable();
                $table->string('tunnel_type', 32)->nullable();
                $table->string('login_time', 64)->nullable();
                $table->unsignedInteger('login_time_utc')->nullable();
                $table->index(['device_id', 'gateway_name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panos_vpn_users');
        Schema::dropIfExists('panos_vpn_gateway_samples');
        Schema::dropIfExists('panos_vpn_gateways');
        Schema::dropIfExists('panos_vpn_tunnel_samples');
        Schema::dropIfExists('panos_vpn_tunnels');
        Schema::dropIfExists('panos_vpn_firewalls');
    }
};
