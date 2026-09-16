<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }

        if (!Schema::hasTable('room_banners')) {
            Schema::create('room_banners', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_id');
                $table->unsignedInteger('order')->default(1);
                $table->text('image_url');
                $table->timestamps();

                $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
                $table->unique(['room_id', 'order']);
            });
        }

        if (Schema::hasColumn('rooms', 'banner_images')) {
            $rooms = DB::table('rooms')->select('id', 'banner_images')->get();
            foreach ($rooms as $room) {
                $images = json_decode((string) $room->banner_images, true);
                if (!is_array($images)) {
                    continue;
                }

                $order = 1;
                foreach ($images as $imageUrl) {
                    $imageUrl = trim((string) $imageUrl);
                    if ($imageUrl === '') {
                        continue;
                    }

                    DB::table('room_banners')->insert([
                        'room_id' => $room->id,
                        'order' => $order,
                        'image_url' => $imageUrl,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $order++;
                }
            }

            Schema::table('rooms', function (Blueprint $table) {
                $table->dropColumn('banner_images');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }

        if (Schema::hasTable('room_banners')) {
            if (!Schema::hasColumn('rooms', 'banner_images')) {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->json('banner_images')->nullable()->after('prize_image_url');
                });
            }

            $roomIds = DB::table('rooms')->pluck('id');
            foreach ($roomIds as $roomId) {
                $images = DB::table('room_banners')
                    ->where('room_id', $roomId)
                    ->orderBy('order')
                    ->pluck('image_url')
                    ->all();

                DB::table('rooms')
                    ->where('id', $roomId)
                    ->update(['banner_images' => json_encode($images)]);
            }

            Schema::dropIfExists('room_banners');
        }
    }
};
