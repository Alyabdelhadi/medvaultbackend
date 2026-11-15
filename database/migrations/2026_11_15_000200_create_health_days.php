<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health Days: awareness-day notifications sent on their date every year
 * (World Diabetes Day, Pink October...). Each goes, in its own language, to
 * health_tips_en / health_tips_ar, never to `medvault`: the app has a
 * separate Health tips switch (on by default), so tips can be turned off
 * without losing result alerts.
 *
 * The calendar ships as inactive drafts. Medical wording goes out under the
 * MedVault name, so a person reads and switches each one on.
 *
 * Only fixed dates. A day that moves every year (World Kidney Day, the second
 * Thursday of March) is added by hand with that year's date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_days', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            // Beirut time, HH:MM.
            $table->string('send_time', 5)->default('10:00');
            $table->string('title_en', 120);
            $table->string('body_en', 300);
            $table->string('title_ar', 120);
            $table->string('body_ar', 300);
            $table->string('link_url')->nullable();
            // A lab paying for the day, for billing and the admin list. Not in the push.
            $table->unsignedBigInteger('sponsor_lab_id')->nullable();
            $table->boolean('is_active')->default(false);
            // Per language, so a send that failed for one is retried without
            // repeating the other.
            $table->date('last_sent_en')->nullable();
            $table->date('last_sent_ar')->nullable();
            $table->timestamps();
            $table->index(['month', 'day']);
        });

        $now = now();
        DB::table('health_days')->insert(array_map(fn (array $d) => [
            'name' => $d[0], 'month' => $d[1], 'day' => $d[2],
            'title_en' => $d[3], 'body_en' => $d[4], 'title_ar' => $d[5], 'body_ar' => $d[6],
            'send_time' => '10:00', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
        ], self::CALENDAR));
    }

    public function down(): void
    {
        Schema::dropIfExists('health_days');
    }

    /** name, month, day, title EN, body EN, title AR, body AR */
    private const CALENDAR = [
        ['World Cancer Day', 2, 4,
            'World Cancer Day',
            'Many cancers are easier to treat when found early. Ask your doctor which screenings suit your age.',
            'اليوم العالمي للسرطان',
            'يسهل علاج الكثير من أنواع السرطان عند اكتشافها مبكرًا. اسأل طبيبك عن الفحوصات المناسبة لعمرك.'],
        ['World Obesity Day', 3, 4,
            'World Obesity Day',
            'Weight affects sugar, cholesterol and blood pressure. A yearly check-up shows where you stand.',
            'اليوم العالمي للسمنة',
            'يؤثر الوزن على السكر والكوليسترول وضغط الدم. الفحص السنوي يوضح لك وضعك.'],
        ['World Health Day', 4, 7,
            'World Health Day',
            'A good day for a yearly check-up. Your results stay together in MedVault.',
            'يوم الصحة العالمي',
            'يوم مناسب لفحصك السنوي. نتائجك تبقى محفوظة معًا في MedVault.'],
        ['World Hypertension Day', 5, 17,
            'World Hypertension Day',
            'High blood pressure often has no symptoms. Have it measured at least once a year.',
            'اليوم العالمي لارتفاع ضغط الدم',
            'غالبًا ما يكون ارتفاع ضغط الدم بلا أعراض. قِس ضغطك مرة في السنة على الأقل.'],
        ['World No Tobacco Day', 5, 31,
            'World No Tobacco Day',
            'Quitting smoking helps your heart and lungs at any age. Your doctor can help you start.',
            'اليوم العالمي للامتناع عن التبغ',
            'الإقلاع عن التدخين يفيد قلبك ورئتيك في أي عمر. يمكن لطبيبك مساعدتك في البداية.'],
        ['World Blood Donor Day', 6, 14,
            'World Blood Donor Day',
            'One donation can help save lives. Ask your nearest hospital how to donate.',
            'اليوم العالمي للمتبرعين بالدم',
            'تبرّع واحد قد يساعد في إنقاذ حياة. اسأل أقرب مستشفى عن طريقة التبرع.'],
        ['World Hepatitis Day', 7, 28,
            'World Hepatitis Day',
            'Hepatitis B and C can go unnoticed for years. A simple blood test can tell.',
            'اليوم العالمي لالتهاب الكبد',
            'قد يبقى التهاب الكبد B وC دون أعراض لسنوات. فحص دم بسيط يكشفه.'],
        ['World Heart Day', 9, 29,
            'World Heart Day',
            'Cholesterol, blood sugar and blood pressure all affect your heart. When did you last check them?',
            'اليوم العالمي للقلب',
            'الكوليسترول وسكر الدم وضغط الدم كلها تؤثر على قلبك. متى فحصتها آخر مرة؟'],
        ['Breast Cancer Awareness Month', 10, 1,
            'Pink October',
            'Breast cancer is most treatable when found early. Women over 40: ask your doctor about a mammogram.',
            'تشرين الوردي',
            'يكون علاج سرطان الثدي أنجح عند اكتشافه مبكرًا. للنساء فوق الأربعين: اسألن الطبيب عن صورة الماموغرام.'],
        ['World Osteoporosis Day', 10, 20,
            'World Osteoporosis Day',
            'Calcium and vitamin D matter for strong bones. Ask your doctor if you should be tested.',
            'اليوم العالمي لهشاشة العظام',
            'الكالسيوم والفيتامين D مهمّان لعظام قوية. اسأل طبيبك إن كنت بحاجة إلى فحص.'],
        ["Men's Health Month", 11, 1,
            "Men's Health Month",
            'Men often skip check-ups. Book yours this month, and ask about prostate screening after 50.',
            'شهر صحة الرجل',
            'كثيرًا ما يؤجل الرجال فحوصاتهم. احجز فحصك هذا الشهر، واسأل عن فحص البروستات بعد سن الخمسين.'],
        ['World Diabetes Day', 11, 14,
            'World Diabetes Day',
            'A fasting glucose or HbA1c test can catch diabetes early. When did you last check yours?',
            'اليوم العالمي للسكري',
            'فحص السكر الصيامي أو HbA1c يكشف السكري مبكرًا. متى أجريت آخر فحص؟'],
        ['World AIDS Day', 12, 1,
            'World AIDS Day',
            'Testing is quick and confidential, and early treatment works. Talk to your doctor.',
            'اليوم العالمي للإيدز',
            'الفحص سريع وسرّي، والعلاج المبكر فعّال. تحدّث مع طبيبك.'],
    ];
};
