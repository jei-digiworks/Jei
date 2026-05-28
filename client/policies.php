<?php
// client/policies.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_client();
run_cron_simulator($pdo);

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Notifications unread count
$stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmt_notif->execute([$user_id]);
$unread_count = $stmt_notif->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Policies - RDG Tennis Player Portal</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid{background-image:linear-gradient(#e5e7eb 1px,transparent 1px),linear-gradient(90deg,#e5e7eb 1px,transparent 1px);background-size:64px 64px;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .fill-icon{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .fade-in{animation:fadeIn .3s ease both}
</style>
<script>
tailwind.config={darkMode:"class",theme:{extend:{
    colors:{"primary":"#154212","primary-container":"#2d5a27","accent":"#FFB800","on-surface":"#1c1b1b","surface-container-low":"#f6f3f2","outline-variant":"#c2c9bb","secondary":"#7d5700","error":"#ba1a1a"},
    borderRadius:{DEFAULT:"0px",full:"9999px"},
    fontFamily:{headline:["Space Grotesk"],body:["Lexend"]}
}}}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="bg-[#fcf9f8] text-[#1c1b1b] font-body court-grid min-h-screen">

<!-- TopBar -->
<header class="fixed top-0 left-0 right-0 z-50 bg-white flex justify-between items-center px-6 h-20 border-b-2 border-primary">
    <div class="flex items-center gap-3">
        <img alt="RDG" class="h-10 w-auto" src="/RDG/RDG Logo.jpg"/>
    </div>
    <div class="flex items-center gap-4">
        <button id="notif-btn" onclick="toggleNotif()" class="relative p-2 hover:bg-[#f6f3f2] transition-colors">
            <span class="material-symbols-outlined text-2xl">notifications</span>
            <?php if($unread_count>0): ?><span class="absolute top-2 right-2 w-2.5 h-2.5 bg-accent rounded-full border border-white"></span><?php endif; ?>
        </button>
        <div id="notif-dd" class="hidden absolute top-16 right-24 w-80 bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] z-50">
            <div class="px-4 py-2 border-b-2 border-primary flex justify-between bg-zinc-50">
                <span class="font-headline font-bold text-xs uppercase text-primary">Notifications</span>
                <button onclick="markAllRead()" class="text-[10px] font-bold text-primary hover:underline uppercase">Mark all read</button>
            </div>
            <div id="notif-items" class="divide-y max-h-60 overflow-y-auto"><div class="p-4 text-xs text-center text-zinc-400">Loading...</div></div>
        </div>
        <div class="h-8 w-px bg-[#c2c9bb]"></div>
        <span class="hidden md:inline font-bold text-xs uppercase text-primary"><?= htmlspecialchars($user_name) ?></span>
        <a href="/RDG/auth/logout.php" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-accent hover:opacity-80 transition-opacity">
            <span class="material-symbols-outlined text-xl">logout</span>
        </a>
    </div>
</header>

<!-- Sidebar -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40">
    <div class="px-6 py-4 mb-2 border-b border-zinc-200">
        <p class="font-headline font-bold uppercase text-sm text-primary">Player Portal</p>
        <p class="text-xs text-[#42493e] font-bold uppercase">RDG Athlete</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="book_slot.php"><span class="material-symbols-outlined">sports_tennis</span> Book Slot</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="history.php"><span class="material-symbols-outlined">history</span> History</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="my_bookings.php"><span class="material-symbols-outlined">confirmation_number</span> My Bookings</a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="policies.php"><span class="material-symbols-outlined fill-icon">policy</span> Policies</a>
    </nav>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
    <div class="max-w-6xl mx-auto space-y-8 fade-in">
        <!-- Hero Header -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 border-b-4 border-primary pb-6">
            <div>
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Facility Policies</h1>
                <p class="text-[#42493e] mt-1">Official guidelines and standards of conduct for all RDG athletes.</p>
            </div>
            <div class="h-1 bg-accent w-24 md:hidden"></div>
        </div>

        <!-- Bento Grid Policy Layout -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
            <!-- Section 1: Court Regulations -->
            <section class="md:col-span-8 bg-white border-2 border-primary p-6 relative overflow-hidden shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute top-0 left-0 w-2 h-full bg-primary"></div>
                <div class="flex items-start justify-between mb-6 pl-4">
                    <div>
                        <h2 class="font-headline font-black text-2xl text-primary uppercase tracking-tight">Court Regulations</h2>
                        <p class="text-xs font-bold text-secondary uppercase">Standards &amp; Conduct</p>
                    </div>
                    <span class="material-symbols-outlined text-primary text-4xl">rule</span>
                </div>
                <div class="space-y-4 pl-4">
                    <div class="flex gap-4 p-4 bg-[#f6f3f2] border-2 border-primary">
                        <span class="font-headline font-black text-2xl text-primary">01</span>
                        <div>
                            <h3 class="font-headline font-bold text-sm text-primary uppercase">Proper Footwear</h3>
                            <p class="text-xs text-on-surface mt-1">Non-marking tennis-specific shoes are mandatory. Running shoes or casual trainers are strictly prohibited to preserve the premium court surface.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 bg-white border-2 border-primary">
                        <span class="font-headline font-black text-2xl text-primary">02</span>
                        <div>
                            <h3 class="font-headline font-bold text-sm text-primary uppercase">Mandatory Check-In</h3>
                            <p class="text-xs text-on-surface mt-1">Athletes must check in at the reception desk at least 15 minutes before their scheduled slot. Late arrivals beyond 15m may result in slot forfeiture.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 bg-[#f6f3f2] border-2 border-primary">
                        <span class="font-headline font-black text-2xl text-primary">03</span>
                        <div>
                            <h3 class="font-headline font-bold text-sm text-primary uppercase">Equipment Care</h3>
                            <p class="text-xs text-on-surface mt-1">Only RDG-approved coaching equipment is allowed on court. Players are responsible for clearing the court of all balls and trash after their session.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 2: Cancellation Policy -->
            <section class="md:col-span-4 bg-primary text-white p-6 flex flex-col justify-between border-2 border-primary shadow-[4px_4px_0px_rgba(125,87,0,1)]">
                <div>
                    <div class="flex justify-between items-start mb-6">
                        <h2 class="font-headline font-black text-3xl uppercase tracking-tighter leading-none">Cancellation<br/>Policy</h2>
                        <span class="material-symbols-outlined text-accent text-4xl">event_busy</span>
                    </div>
                    <div class="space-y-6">
                        <div>
                            <p class="text-xs font-bold text-accent uppercase tracking-wider mb-2">Notice Period</p>
                            <p class="text-sm">A minimum of 48 hours notice is required for all slot cancellations to receive a full refund or credit.</p>
                        </div>
                        <div class="p-4 border-l-4 border-accent bg-[#2d5a27] space-y-1">
                            <p class="text-xs font-bold text-accent uppercase">Late Cancellation</p>
                            <p class="text-xs opacity-90">Cancellations within 24-48h will incur a 50% penalty fee. Less than 24h notice will result in full charge.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Section 3: Payment Terms -->
            <section class="md:col-span-6 bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-accent p-2 border border-primary">
                        <span class="material-symbols-outlined text-primary text-2xl font-bold">payments</span>
                    </div>
                    <h2 class="font-headline font-black text-2xl text-primary uppercase">Payment Terms</h2>
                </div>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 border-b-2 border-[#f6f3f2]">
                        <span class="text-sm font-bold">Booking Deposit</span>
                        <span class="text-xs font-bold bg-[#f6f3f2] px-2.5 py-1 text-primary">50% Advance</span>
                    </div>
                    <div class="flex justify-between items-center p-3 border-b-2 border-[#f6f3f2]">
                        <span class="text-sm font-bold">Pay-Later Limit</span>
                        <span class="text-xs font-bold bg-[#f6f3f2] px-2.5 py-1 text-primary">24-Hour Rule</span>
                    </div>
                    <p class="text-xs text-on-surface-variant p-4 bg-[#f6f3f2] border-2 border-primary mt-4">
                        <strong class="text-primary">The 24h Rule:</strong> "Pay Later" bookings must be settled within 24 hours of creation, or the system will automatically release the slot for public booking.
                    </p>
                </div>
            </section>

            <!-- Section 4: Facility Rules -->
            <section class="md:col-span-6 bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="flex items-center gap-4 mb-6">
                    <div class="bg-primary p-2 border border-primary">
                        <span class="material-symbols-outlined text-accent text-2xl font-bold">stadium</span>
                    </div>
                    <h2 class="font-headline font-black text-2xl text-primary uppercase">Facility Rules</h2>
                </div>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-accent mt-0.5">check_circle</span>
                        <span class="text-xs">Silence mobile devices when near active courts to maintain player focus.</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-accent mt-0.5">check_circle</span>
                        <span class="text-xs">Respect court transition times &mdash; finish your session 2 minutes prior to the hour.</span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Footer -->
        <footer class="mt-12 pt-8 border-t-2 border-primary flex flex-col md:flex-row justify-between items-center gap-6">
            <p class="text-xs text-zinc-500">Last updated: October 2023. RDG Tennis reserves the right to modify policies without prior notice.</p>
            <div class="flex gap-4">
                <button onclick="downloadPDF()" class="px-6 py-2 border-2 border-primary text-primary font-headline font-bold uppercase hover:bg-primary hover:text-white transition-colors">Download PDF</button>
            </div>
        </footer>
    </div>
</main>

<script>
function toggleNotif(){
    const dd=document.getElementById('notif-dd');dd.classList.toggle('hidden');
    if(!dd.classList.contains('hidden'))loadNotif();
}
function loadNotif(){
    const c=document.getElementById('notif-items');
    c.innerHTML='<div class="p-4 text-xs text-center text-zinc-400">Loading...</div>';
    fetch('../actions/get_notifications.php?action=get_unread').then(r=>r.json()).then(d=>{
        if(!d.success||!d.notifications.length){c.innerHTML='<div class="p-4 text-xs text-center text-zinc-400">No unread notifications.</div>';return;}
        c.innerHTML=d.notifications.map(n=>`<div class="p-4 hover:bg-zinc-50 flex justify-between gap-2">
            <div><p class="text-xs font-bold text-primary">${n.message}</p><span class="text-[9px] text-zinc-400 font-bold">${n.time_ago}</span></div>
            <button onclick="markRead(${n.id})" class="text-zinc-300 hover:text-primary"><span class="material-symbols-outlined text-sm">close</span></button>
        </div>`).join('');
    });
}
function markRead(id){const fd=new FormData();fd.append('notification_id',id);fetch('../actions/get_notifications.php?action=mark_read',{method:'POST',body:fd}).then(()=>loadNotif());}
function markAllRead(){const fd=new FormData();fd.append('notification_id','all');fetch('../actions/get_notifications.php?action=mark_read',{method:'POST',body:fd}).then(()=>loadNotif());}
document.addEventListener('click',e=>{
    if(!document.getElementById('notif-btn').contains(e.target)&&!document.getElementById('notif-dd').contains(e.target))
        document.getElementById('notif-dd').classList.add('hidden');
});

function downloadPDF() {
    const element = document.createElement('div');
    element.innerHTML = `
        <div style="font-family: 'Lexend', sans-serif; color: #1c1b1b; padding: 40px; line-height: 1.5; background-color: #ffffff; width: 700px; border: 4px solid #154212; box-shadow: 6px 6px 0px rgba(21,66,18,1);">
            <!-- Header -->
            <div style="border-bottom: 4px solid #154212; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <img style="height: 50px; width: auto;" src="http://localhost/RDG/RDG%20Logo.jpg" />
                    <div>
                        <h1 style="font-family: 'Space Grotesk', sans-serif; font-weight: 900; font-size: 28px; color: #154212; text-transform: uppercase; margin: 0; letter-spacing: -1px; line-height: 1;">Facility Policies</h1>
                        <div style="font-size: 11px; font-weight: bold; color: #7d5700; text-transform: uppercase; margin-top: 5px;">Official Athlete Standards & Rules</div>
                    </div>
                </div>
                <div style="text-align: right; font-size: 9px; color: #72796e; text-transform: uppercase; font-weight: bold; line-height: 1.3;">
                    RDG Tennis Club<br>
                    Code: RDG-POL-2023-V1<br>
                    Published: Oct 2023
                </div>
            </div>

            <!-- Court Regulations -->
            <div style="margin-bottom: 25px;">
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 900; font-size: 15px; color: white; background-color: #154212; padding: 8px 12px; text-transform: uppercase; margin-bottom: 15px; border: 2px solid #154212;">1. Court Regulations & Conduct</div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">01. Proper Footwear</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Non-marking tennis-specific shoes are mandatory. Running shoes or casual trainers are strictly prohibited to preserve the premium court surface.</p>
                    </div>
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">02. Mandatory Check-In</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Athletes must check in at the reception desk at least 15 minutes before their scheduled slot. Late arrivals beyond 15m may result in slot forfeiture.</p>
                    </div>
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">03. Equipment Care</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Only RDG-approved coaching equipment is allowed on court. Players are responsible for clearing the court of all balls and trash after their session.</p>
                    </div>
                </div>
            </div>

            <!-- Cancellation Policy -->
            <div style="margin-bottom: 25px;">
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 900; font-size: 15px; color: white; background-color: #154212; padding: 8px 12px; text-transform: uppercase; margin-bottom: 15px; border: 2px solid #154212;">2. Cancellation & Waiver Policy</div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">Notice Period Requirements</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">A minimum of 48 hours notice is required for all slot cancellations to receive a full refund or booking credit.</p>
                    </div>
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">Late Cancellation Penalties</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Cancellations made between 24 and 48 hours prior to the slot will incur a 50% penalty fee. Cancellations with less than 24 hours notice will result in a 100% full charge.</p>
                    </div>
                </div>
            </div>

            <!-- Payment & Booking Terms -->
            <div style="margin-bottom: 25px;">
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 900; font-size: 15px; color: white; background-color: #154212; padding: 8px 12px; text-transform: uppercase; margin-bottom: 15px; border: 2px solid #154212;">3. Payment & Reservation Terms</div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">Booking Deposit & Coaching</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Court reservations require online validation or cash/card deposit. Professional coaching is automatically added to client bookings for maximum development.</p>
                    </div>
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">The 24h Pay-Later Rule</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">All "Pay Later" reservations must be settled within 24 hours of booking, or the system will automatically release the slot for public booking.</p>
                    </div>
                </div>
            </div>

            <!-- Facility Rules -->
            <div style="margin-bottom: 25px;">
                <div style="font-family: 'Space Grotesk', sans-serif; font-weight: 900; font-size: 15px; color: white; background-color: #154212; padding: 8px 12px; text-transform: uppercase; margin-bottom: 15px; border: 2px solid #154212;">4. General Facility Rules</div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">Silence Mobile Devices</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">All phones and mobile devices must be silenced or set to vibrate when near active courts to maintain peak player focus.</p>
                    </div>
                    <div style="border: 2px solid #154212; padding: 15px; background-color: #fcf9f8;">
                        <h3 style="font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; color: #154212; text-transform: uppercase; margin: 0 0 5px 0;">Court Transition Courtesy</h3>
                        <p style="font-size: 11px; margin: 0; color: #42493e; line-height: 1.4;">Respect court transition times. Please finish your session 2 minutes prior to the hour to allow a smooth handover for upcoming bookings.</p>
                    </div>
                </div>
            </div>

            <div style="margin-top: 40px; border-top: 2px solid #154212; padding-top: 15px; display: flex; justify-content: space-between; font-size: 9px; color: #72796e; font-weight: bold; text-transform: uppercase; width: 100%;">
                <span>© 2023 RDG Tennis Club. All Rights Reserved.</span>
                <span>Official PDF Guidelines</span>
            </div>
        </div>
    `;

    const opt = {
        margin:       10,
        filename:     'RDG_Tennis_Guidelines.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(element).save();
}
</script>
</body>
</html>
