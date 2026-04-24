System Audit Report
1. Current Runtime Flow (As-Is)
Pesan pertama kali masuk di Baileys listener agentManager.js (line 142), lalu dinormalisasi dan dikirim sebagai webhook message_received dengan idempotency key dari webhookSender.js (line 19).
Laravel menerima webhook di routes/api.php (line 7) lalu masuk ke WebhookController.php (line 18), yang memvalidasi X-Baileys-Secret dan meneruskan payload ke ingress service.
WebhookIngressService.php (line 26) melakukan early exit untuk payload tidak valid, cache dedupe webhook 24 jam, ignore is_from_me, lalu dispatch ProcessInboundMessageJob.
ProcessInboundMessageJob.php (line 43) mengambil conversation lock, membuat inbound receipt via duplicate guard, skip jika duplicate/ignored, ingest message, lalu update state inbound.
Jika pesan media, job memanggil media handler lalu hanya mencatat log “auto-reply queued”; saya tidak menemukan dispatch reply aktual di path ini. Ini mismatch nyata antara log dan behavior.
Jika pesan teks normal, job dispatch RunAgentCoreJob.php (line 30).
RunAgentCoreJob early-exit bila lead/conversation hilang, human takeover aktif, automation paused, atau inbound sudah superseded oleh pesan baru; lalu memanggil AgentOrchestrator.php (line 67).
AgentOrchestrator menjalankan flow aktual: rule interpretation, history continuation terbatas, guardrail, shortcut pricelist, classifier LLM, merge hasil rules+LLM, intent guards, stage/state updates, branch bisnis spesifik, lalu generic response generation bila tidak tertangani branch khusus.
Setelah wording terbentuk, ada pipeline tambahan: invalid handoff guard, fallback guard, quality filter, humanizer, topic validation, evaluator, superseded check, queue outbound, update outbound state, dan refresh summary async.
Outbound dikirim lewat OutboundDispatchService.php (line 27), yang punya dedupe sendiri dan skip jika agent disconnected atau pesan kosong; Baileys sidecar juga masih punya outbound dedupe tambahan di agentManager.js (line 275).
2. Component Map
Inbound transport: agentManager.js (line 142), webhookSender.js (line 19).
Webhook entry: WebhookController.php (line 18), WebhookIngressService.php (line 26).
Deduping/ingest: DuplicateMessageGuardService.php (line 19), MessageIngestService.php (line 17).
Core runtime coordinator: AgentOrchestrator.php (line 67).
Interpretation layer: IntentExtractionService.php (line 9), SlotExtractionService.php (line 10), ConversationInterpretationService.php (line 16).
State/memory: ConversationStateService.php (line 28), ConversationStageService.php (line 73), LeadMemoryService.php (line 11).
Prompt/context/LLM: ContextAssembler.php (line 45), PromptBuilder.php (line 31), OpenAiLlmClient.php (line 34).
Response safety: FallbackGuardService.php (line 21), QualityFilterService.php (line 42).
Logging/observability: ConversationTurnLogger.php (line 131), TransitionConversationStageAction.php (line 16).
3. Analyzer Audit
LLM analyzer dipanggil dari AgentOrchestrator::runClassifier() di AgentOrchestrator.php (line 540).
Prompt analyzer dibangun oleh PromptBuilder.php (line 31) dan meminta JSON terstruktur: intent, sentiment, extracted fields, current stage, suggested next stage, missing critical fields, handoff flag, confidence.
Input analyzer berasal dari ContextAssembler.php (line 45): tenant policy, tone profile, lead memory, service boundary, structured state, conversation state, active user focus, latest user ask, booking gaps, dan recent messages. Summary justru tidak ikut ke classifier; summary dipakai di responder.
Output analyzer bukan free text. OpenAiLlmClient memakai schema JSON untuk classifier di OpenAiLlmClient.php (line 101), lalu divalidasi lagi oleh DTO/parsing.
Analyzer tidak menjadi pemilik final decision. Hasilnya masih di-merge dengan rules lalu dioverride lagi oleh intent guards dan stage rules backend.
Risiko utama: classifier menerima banyak konteks semantik, tetapi tidak menerima summary yang sama dengan responder, sehingga analyzer dan responder bisa “ingat” percakapan secara berbeda.
4. Decision Layer Audit
Decision layer nyata ada di AgentOrchestrator, bukan controller/router yang terpisah.
Decision bersifat hybrid. Intent awal datang dari rule extraction + LLM, tetapi branch flow ditentukan oleh if/else deterministik di orchestrator dan stage update final ditentukan backend di ConversationStageService.php (line 73).
Tidak ada intent-to-handler mapping yang eksplisit. Routing tertanam dalam urutan branch di satu class besar, sehingga order branch menjadi arsitektur de facto.
Fallback juga diputuskan di orchestrator, bukan di layer terpisah. Jadi analyzer, business decision, fallback, dan response repair bercampur dalam satu runtime flow.
Ambiguity rendah pada codepath utama, tetapi ada banyak “possible path” karena banyak early exit dan branch prioritas.
5. Business Logic Audit
Handler bisnis relevan mayoritas adalah private method di AgentOrchestrator, bukan service handler yang terpisah rapi.
Branch yang terlihat jelas: opt-out, negative sentiment/handoff, pricelist inquiry, booking/ready-to-book, booking field reply, grounded package reply, complaint recovery, dan generic response path.
Supporting services yang benar-benar menangani domain: PricelistService, LeadBookingDataService, BookingSchemaService, KnowledgeRetrievalService, HandoffRequestService, LeadMemoryService.
Output handler tidak konsisten. Sebagian handler menghasilkan natural reply langsung, sebagian hanya menyiapkan kondisi lalu menyerahkan isi jawaban ke LLM responder.
Risiko utamanya: “apa yang harus dilakukan” dan “bagaimana mengucapkannya” belum dipisah. Akibatnya responder masih ikut menentukan isi bisnis pada banyak path.
6. State & Memory Audit
Stage/state disimpan di beberapa tempat: conversations, conversation_states, lead_memories, conversation_summaries, conversation_stage_transitions, dan conversation_turn_logs.
Structured state dikelola oleh ConversationStateService.php (line 28), termasuk filled_slots, unresolved_questions, asked_fields, next_expected_field, dan next_best_action.
Stage transition final diputuskan oleh ConversationStageService.php (line 73) dan dicatat oleh TransitionConversationStageAction.php (line 16).
Memory durable ada di LeadMemoryService.php (line 11). Summary diperbarui async, bukan inline.
Sistem memakai kombinasi full recent messages + structured state + memory + summary, tetapi pembagiannya tidak konsisten: classifier tidak memakai summary, responder memakai summary, dan ada helper continuation dari history yang hanya menangani kasus package pendek.
Flags bisnis seperti package_interest, pricing_focus, payment_topic, booking fields, dan inquiry fields memang ada, tetapi belum menjadi policy contract tunggal yang dipakai semua branch.
7. Responder Audit
Wording final dibuat dari AgentOrchestrator::runResponse() dengan context dari ContextAssembler.php (line 45) dan prompt dari PromptBuilder.php (line 70).
Prompt responder sudah punya guardrail kuat: jawab pertanyaan terakhir dulu, pakai state sebagai source of truth, jangan halusinasi availability/price, jangan janji admin follow-up sembarangan, jangan regress ke discovery setelah payment/booking signal.
Namun responder masih ikut menentukan isi bisnis pada generic path, karena tidak selalu menerima payload bisnis yang sudah final.
Setelah LLM menghasilkan draft, masih ada fallback guard, quality filter, humanizer, dan topic validation. Ini membantu safety, tetapi juga berarti output final bisa jauh dari decision awal.
8. Failure/Fallback Audit
Jika analyzer gagal, orchestrator mencoba fallback ke rules bila intent rule cukup jelas; jika tidak, ia bisa membuat fallback stage-aware atau no-reply tergantung kondisi.
Jika generator gagal, ada beberapa path: retry, intent-aware fallback, generic fallback, atau no-reply jika error dianggap non-transient atau agent unavailable.
Kondisi no-reply nyata yang saya temukan: guardrail blocked, missing WhatsApp agent, superseded inbound, fallback tidak ter-dispatch, agent disconnected saat send, dan pesan kosong.
Fallback tidak selalu pasti terkirim. OutboundDispatchService bisa skip send jika agent disconnected atau payload kosong, dan orchestrator memang memiliki log path khusus fallback_not_dispatched_*.
Temuan penting: path media menyatakan auto-reply queued, tetapi saya tidak menemukan dispatch aktual. Ini kandidat bug observability/behavior yang jelas.
9. Root Architecture Problems
AgentOrchestrator terlalu besar dan menjadi campuran interpreter, policy engine, business handler, fallback manager, responder, evaluator, dan dispatcher.
Decision tersebar di rules, classifier, intent guards, stage rules, dan response repair. Ini membuat jawaban terasa “ngaco” karena authority tidak tunggal.
Structured state sudah ada, tetapi belum menjadi kontrak utama untuk semua cabang. Beberapa cabang masih lompat langsung ke wording.
Continuity multi-turn masih setengah jadi. Ada memory dan summary, tetapi classifier dan responder memakai konteks yang berbeda.
Booking flow masih sangat heuristik pada beberapa titik, sehingga field bisa tertangkap longgar dan stage bisa lompat.
Duplicate safety kuat, tetapi berlapis dan tersebar di banyak titik, sehingga sulit diaudit sebagai satu mekanisme terpadu.
10. Minimal Refactor Opportunities
Pisahkan TurnDecisionService kecil dari AgentOrchestrator agar intent final, action final, dan fallback reason berada di satu tempat deterministik.
Ubah handler bisnis agar mengembalikan payload terstruktur, lalu responder hanya memoles bahasa.
Kirim structured summary ringkas ke classifier juga, bukan hanya ke responder.
Jadikan routing branch sebagai priority map/rule table, bukan urutan if panjang.
Tambahkan validasi field-level untuk booking reply sebelum LeadBookingDataService menyimpan data.
Rapikan outcome observability menjadi enum outcome tunggal: replied, fallback_replied, no_reply_guardrail, no_reply_superseded, dan seterusnya.
Perbaiki atau hapus path “media auto-reply queued” jika memang belum pernah mengirim reply.
11. Files to Review Next
AgentOrchestrator.php (line 67)
ConversationInterpretationService.php (line 16)
IntentExtractionService.php (line 9)
SlotExtractionService.php (line 10)
ConversationStageService.php (line 73)
ConversationStateService.php (line 28)
ContextAssembler.php (line 45)
PromptBuilder.php (line 31)
ProcessInboundMessageJob.php (line 43)
OutboundDispatchService.php (line 27)
agentManager.js (line 142)
Findings

Flow aktual sudah punya banyak guardrail, dedupe, state, memory, dan observability.
Masalah utama bukan “tidak ada state”, tetapi authority decision yang tersebar dan kontrak antar-layer yang belum tegas.
Bagian paling berisiko saat ini adalah AgentOrchestrator karena hampir semua behavior penting bertemu di sana.
Problem List

Analyzer, rules, guards, dan stage engine sama-sama ikut memutuskan.
Handler bisnis belum konsisten antara structured payload vs direct natural reply.
Classifier dan responder memakai memori/konteks yang tidak simetris.
Ada beberapa path no-reply yang sah, tetapi observability-nya masih membingungkan untuk operasional.
Ada indikasi mismatch log-vs-runtime pada media auto-reply.
Proposed Changes

Audit ini belum mengubah kode.
Refactor berikutnya paling aman dimulai dari decision contract, bukan langsung ganti prompt atau rewrite total.
Files Changed

Tidak ada. Audit saja.
Risks / Edge Cases

Beberapa path bergantung pada urutan branch, jadi perubahan kecil bisa mengubah runtime behavior cukup besar.
Superseded inbound logic memang mencegah balasan usang, tetapi juga bisa terlihat seperti “reply hilang”.
Outbound skip saat agent disconnected membuat fallback terlihat seolah dibuat tetapi tidak pernah terkirim.
Tests Added or Recommended

Tidak ada test baru yang ditambahkan.
Existing test yang relevan ada di app/Modules/AgentCore/Tests dan app/Modules/WhatsApp/Tests, termasuk duplicate handling, classifier fallback, fallback guard, dan quality filter.
Test tidak bisa saya jalankan karena container Docker belum running; docker compose exec -T app php artisan test gagal dengan kondisi service app tidak aktif.
Recommended berikutnya: long-thread topic continuation, booking-field miscapture, media auto-reply path, dan fallback-dispatch-vs-no-reply outcome matrix.

Phase 2 Audit Addendum
12. Detailed Runtime Decision Tree
Codepath phase-2 yang paling menentukan ada di AgentOrchestrator.php line 132.
Urutan branch aktual saat satu inbound text masuk adalah:
1. record inbound state lebih dulu via ConversationStateService::recordInboundMessage() di app/Modules/Conversations/Services/ConversationStateService.php:48.
2. jalankan rules-only interpretation via ConversationInterpretationService::interpret() di app/Modules/AgentCore/Services/ConversationInterpretationService.php:16, lalu patch history singkat via AgentOrchestrator::continueInterpretationFromHistory() di app/Modules/AgentCore/Services/AgentOrchestrator.php:3378.
3. guardrail check. Jika blocked, flow berhenti sebagai no-reply di AgentOrchestrator.php:139-145.
4. cek WhatsApp agent. Jika tidak ada, flow berhenti sebagai no-reply di AgentOrchestrator.php:150-156.
5. shortcut direct pricelist. Jika message terdeteksi direct pricelist inquiry, classifier LLM dilewati total. Flow langsung promote stage, sync memory/state, buat synthetic decision, lalu kirim pricelist lewat handlePricelistInquiry() di AgentOrchestrator.php:160-203 dan 1912-2039.
6. jalankan classifier LLM di runClassifier() pada AgentOrchestrator.php:619. Jika gagal, system membuat classifier sintetis dari rules di AgentOrchestrator.php:214-251.
7. kumpulkan intent guard signals di collectIntentGuardSignals() pada AgentOrchestrator.php:3513. Di sini booking/payment/package bisa dioverride sebelum final decision.
8. bangun TurnDecisionInput dan kirim ke TurnDecisionService::decide() di app/Modules/AgentCore/Services/TurnDecisionService.php:31.
9. jika final action = DoNotReply, flow berhenti di AgentOrchestrator.php:291-311.
10. jika final action = ReplyWithFallback, orchestrator mengirim controlled fallback via queueControlledClassifierFallbackReply() di AgentOrchestrator.php:322-339 dan 2279-2328.
11. jika lanjut normal, system baru menghitung risk, upsert lead memory, apply stage engine, apply structured state, lalu sync state.next_best_action di AgentOrchestrator.php:342-369.
12. setelah state diterapkan, branch prioritas dijalankan dengan urutan tetap:
- opt-out di AgentOrchestrator.php:377-381
- pricelist repeat complaint di AgentOrchestrator.php:383-397
- negative sentiment handoff di AgentOrchestrator.php:400-404
- guide to booking di AgentOrchestrator.php:407-412
- pricelist clarification di AgentOrchestrator.php:415-429
- booking field reply di AgentOrchestrator.php:432-435
- generic handoff di AgentOrchestrator.php:437-441
13. kalau semua branch di atas tidak match, baru masuk ke path umum:
- update lead stage
- grounded package reply jika eligible di AgentOrchestrator.php:440-451 dan 2332-2391
- auto-send pricelist jika eligible di AgentOrchestrator.php:453-457 dan 2048-2081
- generic LLM response di AgentOrchestrator.php:460-602
14. generic LLM response masih melewati rewrite chain: invalid handoff guard, fallback guard, quality filter, humanizer, topic validation, evaluator, superseded check, asked-field detector, lalu queue outbound.

Implikasi phase-2:
Order branch adalah arsitektur sebenarnya. Handler map formal belum menjadi otoritas runtime; urutan if/else itulah router-nya.

13. Decision Authority Map
Authority final bukan di satu layer, tetapi tersebar berlapis:
- Rules extractor: IntentExtractionService.php:9 memberi legacy intent cepat dari string match/regex.
- LLM classifier: AgentOrchestrator::runClassifier() di AgentOrchestrator.php:619 memberi structured JSON intent/stage/signal.
- Interpretation merger: ConversationInterpretationService::resolveClassifierOutput() di app/Modules/AgentCore/Services/ConversationInterpretationService.php:65 masih bisa mengembalikan classifier ke rule intent tertentu.
- Guard override layer: collectIntentGuardSignals() di AgentOrchestrator.php:3513 bisa mengganti booking ke package, payment ke pricing/package, atau booking ke other.
- Final action decider: TurnDecisionService::decide() di TurnDecisionService.php:31 memilih intent final, action final, stage_after prediction, fallback reason, handoff need.
- Stage authority: ConversationStageService::decideAndApply() di app/Modules/Conversations/Services/ConversationStageService.php:73 tetap punya hak final untuk stage efektif. Prediksi stage dari TurnDecisionService hanya advisory lalu disinkronkan lagi di AgentOrchestrator.php:936.
- Branch authority: walau finalAction sudah ditentukan, user experience terakhir tetap bergantung pada branch order di orchestrator. Contoh: grounded package reply dan auto-send pricelist masih ditentukan oleh helper boolean di orchestrator, bukan oleh TurnDecisionService.
- Post-response authority: fallback guard, quality filter, humanizer, dan topic validator dapat mengubah wording final setelah decision awal dibuat.

Kesimpulan phase-2:
Otoritas intent sudah mulai dipusatkan ke TurnDecisionService, tetapi otoritas action bisnis dan wording akhir masih tersebar antara orchestrator, helper branch, dan post-processing chain.

14. State Mutation Map
Mutasi state aktual per turn tersebar seperti ini:
- Inbound receipt/dedupe disimpan oleh DuplicateMessageGuardService::startInbound() di app/Modules/WhatsApp/Services/DuplicateMessageGuardService.php:19.
- Message conversation record dibuat oleh ingest layer, lalu structured state di-touch pertama kali oleh ConversationStateService::recordInboundMessage() di ConversationStateService.php:48.
- Lead memory diupdate dua kali setelah finalAction lolos dari fallback/no-reply:
  mapExtractedFields(classifier) di AgentOrchestrator.php:344
  mapInterpretationSlotsToMemory(interpretation slots) di AgentOrchestrator.php:345
- Stage efektif ditentukan oleh ConversationStageService::decideAndApply() di ConversationStageService.php:73.
- Structured state disinkronkan lewat ConversationStateService::applyInterpretationResult() di ConversationStateService.php:63.
- next_best_action di state bisa ditimpa lagi oleh syncConversationStateToDecision() di AgentOrchestrator.php:360-368.
- Metadata asked_fields dan next_expected_field di conversation diubah dari dalam ConversationStateService::syncConversationCollectionMetadata() pada ConversationStateService.php:239.
- Setelah outbound dipilih, ConversationStateService::recordOutboundMessage() di ConversationStateService.php:145 menyimpan last_agent_message, last_agent_question, last_answered_topic, next_best_action, dan last_tool_result_summary.
- Summary durable diperbarui async melalui dispatchSummaryRefresh() di AgentOrchestrator.php:1378 yang memanggil ConversationSummaryService.
- Log transisi stage selalu dicatat oleh TransitionConversationStageAction::execute() di app/Modules/Conversations/Actions/TransitionConversationStageAction.php:16, termasuk invalid transition attempt.
- Decision trace final dicatat oleh DecisionTrace::log() di app/Modules/AgentCore/Support/DecisionTrace.php:23.

Risiko nyata dari phase-2 ini:
Ada beberapa writer untuk next_best_action dan asked_fields. Artinya drift state bisa muncul walau intent final sudah benar, terutama kalau reply akhir berubah setelah quality filter/humanizer.

15. Analyzer, Extraction, and Validation Audit
Rules analyzer masih literal-heavy:
- IntentExtractionService::extract() di app/Modules/AgentCore/Services/IntentExtractionService.php:9 memakai kombinasi str_contains dan regex. Ini cepat, tetapi rawan false trigger karena sangat tergantung kata/frasa spesifik.
- SlotExtractionService::extract() di app/Modules/AgentCore/Services/SlotExtractionService.php:10 juga regex-heavy untuk tanggal, waktu, lokasi, budget, pricing focus, package interest, payment topic.

Detail penting phase-2:
- extractLocation() di SlotExtractionService bisa menangkap pola luas dari "di ..." di SlotExtractionService.php line 83. Sudah ada invalid fragment filter, tetapi masih heuristik.
- extractPackageInterest() dan extractPricingFocus() di SlotExtractionService line 123 dan 141 berbasis keyword, jadi multi-turn nuance tetap tidak dibaca oleh rules layer.
- mergeSlots() di ConversationInterpretationService line 214 mengutamakan rule slots di atas classifier slots ketika key sama, karena secondary overwrite primary.
- resolveClassifierOutput() di ConversationInterpretationService line 65 melindungi beberapa analyzer intent tertentu, tetapi override masih bersifat ad hoc per kasus.
- Prompt classifier sendiri cukup ketat dan meminta JSON valid di PromptBuilder.php:31.
- Context classifier memang memakai structured state dan recent messages, tetapi summary tidak masuk ke classifier. Summary baru masuk ke mode response lewat ContextAssembler.php:324 dan gating mode di ContextAssembler.php:65.

Validation gap terbesar phase-2:
- BookingFieldReplyHandler::buildPayload() di app/Modules/AgentCore/Handlers/BookingFieldReplyHandler.php:16 langsung mengambil seluruh inbound text sebagai value lalu menyimpan via LeadBookingDataService::upsert() di app/Modules/Booking/Services/LeadBookingDataService.php:14.
- BookingFieldValidationService::validate() memang ada di app/Modules/Booking/Services/BookingFieldValidationService.php:10, tetapi saya tidak menemukan ia dipakai di path maybeHandleBookingFieldReply().

Jadi problem "masih menggunakan pembacaan kata perkata dan lolos dari validasi" terbukti sebagian besar benar pada booking-field path.

16. Special Path Audit
Direct pricelist path
- Branch ini mem-bypass classifier LLM dan generic responder sepenuhnya di AgentOrchestrator.php:160-203.
- Ini membuat behavior pricelist lebih deterministik, tetapi juga membuat jalur pricing punya aturan sendiri yang berbeda dari jalur generic.

Short package continuation path
- continueInterpretationFromHistory() di AgentOrchestrator.php:3378 meng-upgrade intent unclear menjadi tanya_paket jika message pendek dan state/history menunjukkan package context.
- Ini memang memperbaiki continuity untuk follow-up pendek, tetapi implementasinya sempit: hanya package continuation yang ditangani eksplisit.

Ready-to-book guard path
- collectIntentGuardSignals() di AgentOrchestrator.php:3513 dapat menurunkan ready_to_book menjadi tanya_paket atau other.
- Syaratnya adalah harus ada package/pricing context lebih dulu dan recommendation fields dianggap complete melalui hasCompletedBookingRequirements() di AgentOrchestrator.php:3589.

Generic response path
- runResponse() di AgentOrchestrator.php:663 masih menentukan isi bisnis pada cabang umum, bukan hanya wording.
- Ini berarti walau business handlers sudah mulai ada, generic path tetap menjadi fallback besar yang memikul business reasoning sekaligus wording.

17. Failure / No-Reply Matrix (Detailed)
No-reply yang eksplisit saya temukan di phase-2:
- guardrail blocked di AgentOrchestrator.php:139-145
- missing WhatsApp agent di AgentOrchestrator.php:150-156
- finalAction DoNotReply di AgentOrchestrator.php:291-311
- controlled fallback gagal membentuk pesan di AgentOrchestrator.php:339 dan 2297-2300
- response/fallback superseded oleh inbound yang lebih baru di AgentOrchestrator.php:593, 2244, 2305, 2362, 2420, 2823
- outbound kosong tidak di-queue di OutboundDispatchService.php:251
- outbound send/drop saat agent disconnected di OutboundDispatchService.php:42 dan 131
- fallback non-transient error atau agent unavailable di AgentOrchestrator.php:2206-2274

Observability mismatch yang masih valid:
- Media path di ProcessInboundMessageJob.php:115-123 menulis log "Media auto-reply queued", tetapi tidak ada queueSend() atau queueSendDocument() di jalur itu.

18. Phase-2 Root Architecture Problems
Decision contract sudah mulai dibentuk, tetapi belum menjadi single runtime authority.
Branch order di orchestrator masih lebih kuat daripada decision object.
Context symmetry belum tercapai:
- classifier melihat structured state + recent messages
- responder melihat structured state + recent messages + summary + knowledge + response plan
Booking state dan booking data berada di jalur berbeda:
- conversation state mengelola asked_fields/next_expected_field
- lead booking data mengelola field booking aktual
- bridging di antara keduanya masih heuristik
Post-processing reply chain sangat membantu safety, tetapi juga menambah banyak titik perubahan output. Saat output final berubah, state bookkeeping bisa ikut bias.

19. Minimal Refactor Opportunities for Phase 3
Buat branch table eksplisit berbasis FinalAction agar orchestrator tidak lagi mengandalkan urutan if panjang.
Pindahkan grounded package reply dan auto-send pricelist menjadi action resmi di TurnDecisionService, bukan helper boolean terpisah.
Masukkan summary ringkas ke classifier context agar analyzer dan responder tidak memakai memori yang berbeda.
Pasang BookingFieldValidationService di maybeHandleBookingFieldReply() sebelum LeadBookingDataService::upsert().
Pisahkan state writer untuk next_best_action dan asked_fields agar satu turn tidak diupdate oleh banyak tempat tanpa kontrak.
Ubah no-reply/fallback outcome menjadi enum outcome tunggal dan log sekali di akhir turn.

20. Files to Review Next (Phase 2 Priority)
app/Modules/AgentCore/Services/AgentOrchestrator.php:132
app/Modules/AgentCore/Services/TurnDecisionService.php:31
app/Modules/AgentCore/Services/ConversationInterpretationService.php:65
app/Modules/AgentCore/Services/IntentExtractionService.php:9
app/Modules/AgentCore/Services/SlotExtractionService.php:10
app/Modules/Conversations/Services/ConversationStateService.php:63
app/Modules/Conversations/Services/ConversationStageService.php:73
app/Modules/AgentCore/Handlers/BookingFieldReplyHandler.php:16
app/Modules/Booking/Services/LeadBookingDataService.php:14
app/Modules/Booking/Services/BookingFieldValidationService.php:10
app/Modules/WhatsApp/Services/OutboundDispatchService.php:27

Phase 2 Findings
TurnDecisionService sudah menjadi langkah maju, tetapi runtime belum benar-benar turn-decision-driven karena branch special case masih dipilih di orchestrator.
Jalur pricing/package relatif lebih deterministic daripada jalur generic response karena sudah punya shortcut dan business payload path.
Jalur booking-field masih paling rawan karena menyimpan input user secara mentah tanpa validasi field type.
Masalah context drift terutama datang dari context asymmetry classifier vs responder dan dari post-processing chain yang bisa mengubah reply setelah decision dibuat.

Phase 2 Problem List
Authority final intent, action, stage, dan wording masih tersebar.
Branch special case masih di-hardcode sebagai urutan prioritas.
Booking field ingestion belum tervalidasi.
Continuity history yang eksplisit baru kuat di package continuation, belum general multi-topic continuation.
No-reply outcome valid banyak, tetapi pengalaman operasional masih terlihat seperti "reply hilang".

Phase 2 Proposed Changes
Phase-2 ini tetap audit saja, belum mengubah runtime code.
Refactor berikutnya paling aman dimulai dari branch routing contract dan booking-field validation path.
Kalau dilanjut, task refactor paling bernilai adalah memindahkan branch special case menjadi deterministic action handlers berbasis FinalAction.

Phase 2 Files Changed
flow/System Audit Report.md

Phase 2 Risks / Edge Cases
Mengubah urutan branch tanpa contract test hampir pasti menggeser behavior pricing/package/booking.
Menambahkan validator booking field bisa mengubah data yang selama ini lolos mentah, jadi perlu compatibility plan.
Menyatukan authority ke TurnDecisionService tanpa memindahkan helper branch berisiko membuat dua sumber keputusan aktif bersamaan.

Phase 2 Tests Added or Recommended
Saya tidak menambah test baru.
Saya menjalankan test terarah di container Docker:
- app/Modules/AgentCore/Tests/ConversationInterpretationServiceTest.php: pass
- app/Modules/AgentCore/Tests/ContextAssemblerTest.php: pass
- app/Modules/WhatsApp/Tests/ProcessInboundMessageJobTest.php: pass
- app/Modules/AgentCore/Tests/AgentOrchestratorTest.php: 94 pass, 6 fail

Failure yang muncul saat audit phase-2:
- explicit price follow up answers in chat when user did not ask for pdf
- generated reply is humanized when opener repeats previous assistant opener
- quality filter repairs missing cta without replacing the whole reply
- non handoff response strips admin follow up language from llm output
- asked_fields is not recorded when the predicted field is not actually asked
- asked_fields is not recorded when a rewrite removes the field question

Enam failure ini menguatkan temuan phase-2 bahwa area paling rapuh sekarang memang branch ordering, rewrite chain, dan state bookkeeping asked_fields.
