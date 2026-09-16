<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banks = [
            ['code' => 'VCB', 'name' => 'Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)', 'image' => 'banks/vcb.png', 'is_active' => true],
            ['code' => 'TCB', 'name' => 'Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)', 'image' => 'banks/tcb.png', 'is_active' => true],
            ['code' => 'BIDV', 'name' => 'Ngân hàng TMCP Đầu tư và Phát triển Việt Nam (BIDV)', 'image' => 'banks/bidv.png', 'is_active' => true],
            ['code' => 'CTG', 'name' => 'Ngân hàng TMCP Công Thương Việt Nam (VietinBank)', 'image' => 'banks/ctg.png', 'is_active' => true],
            ['code' => 'ACB', 'name' => 'Ngân hàng TMCP Á Châu (ACB)', 'image' => 'banks/acb.png', 'is_active' => true],
            ['code' => 'TPB', 'name' => 'Ngân hàng TMCP Tiên Phong (TPBank)', 'image' => 'banks/tpb.png', 'is_active' => true],
            ['code' => 'MBB', 'name' => 'Ngân hàng TMCP Quân đội (MB Bank)', 'image' => 'banks/mbb.png', 'is_active' => true],
            ['code' => 'STB', 'name' => 'Ngân hàng TMCP Sài Gòn Thương Tín (Sacombank)', 'image' => 'banks/stb.png', 'is_active' => true],
            ['code' => 'VPB', 'name' => 'Ngân hàng TMCP Việt Nam Thịnh Vượng (VPBank)', 'image' => 'banks/vpb.png', 'is_active' => true],
            ['code' => 'SHB', 'name' => 'Ngân hàng TMCP Sài Gòn - Hà Nội (SHB)', 'image' => 'banks/shb.png', 'is_active' => true],
            ['code' => 'HDB', 'name' => 'Ngân hàng TMCP Phát triển TP.HCM (HDBank)', 'image' => 'banks/hdb.png', 'is_active' => true],
            ['code' => 'SEB', 'name' => 'Ngân hàng TMCP Đông Nam Á (SeABank)', 'image' => 'banks/seb.png', 'is_active' => true],
            ['code' => 'EIB', 'name' => 'Ngân hàng TMCP Xuất Nhập khẩu Việt Nam (Eximbank)', 'image' => 'banks/eib.png', 'is_active' => true],
            ['code' => 'NVB', 'name' => 'Ngân hàng TMCP Bắc Á (Bac A Bank)', 'image' => 'banks/nvb.png', 'is_active' => true],
            ['code' => 'VIB', 'name' => 'Ngân hàng TMCP Quốc tế Việt Nam (VIB)', 'image' => 'banks/vib.png', 'is_active' => true],
            ['code' => 'ABB', 'name' => 'Ngân hàng TMCP An Bình (ABBANK)', 'image' => 'banks/abb.png', 'is_active' => true],
            ['code' => 'NAB', 'name' => 'Ngân hàng TMCP Nam Á (Nam A Bank)', 'image' => 'banks/nab.png', 'is_active' => true],
            ['code' => 'BVB', 'name' => 'Ngân hàng TMCP Bảo Việt (Baoviet Bank)', 'image' => 'banks/bvb.png', 'is_active' => true],
            ['code' => 'PGB', 'name' => 'Ngân hàng TMCP Xăng dầu Petrolimex (PG Bank)', 'image' => 'banks/pgb.png', 'is_active' => true],
            ['code' => 'PVCOM', 'name' => 'Ngân hàng TMCP Đại Chúng Việt Nam (PVcomBank)', 'image' => 'banks/pvcom.png', 'is_active' => true],
            ['code' => 'SHN', 'name' => 'Ngân hàng TNHH MTV Shinhan Việt Nam', 'image' => 'banks/shinhan.png', 'is_active' => true],
            ['code' => 'HSB', 'name' => 'Ngân hàng TNHH MTV HSBC (Việt Nam)', 'image' => 'banks/hsbc.png', 'is_active' => true],
        ];

        Bank::upsert($banks, ['code'], ['name', 'image', 'is_active']);
    }
}
