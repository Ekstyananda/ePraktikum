# Rancangan database MySQL
Satu database `Lab`. Skema ini logical baseline, migration normalisasi boleh disempurnakan dengan mempertahankan perilaku. PK bigint atau UUID konsisten; FK, indeks dan constraints wajib. NBI varchar bukan angka. UTC storage, tampilan Asia/Jakarta. Core memakai timestamps. Tidak cascade-delete hasil akademik/log.

|Tabel|Kolom penting / constraints|
|---|---|
|users|name email(unique) password role(admin/aslab) active|
|semesters|code unique label starts_at ends_at status(draft/active/locked)|
|practicums|code unique name description|
|practicum_offerings|semester_id practicum_id status; unique semester+practicum|
|staff_assignments|user_id offering_id all_sessions; unique user+offering|
|staff_session_scopes|assignment_id session_id; dipakai hanya jika all_sessions=false|
|user_permissions|assignment_id permission_key allowed; unique pair; deny override ditetapkan jelas|
|students|nbi unique name|
|enrollments|offering_id student_id sim_class class_category supervisor_id nullable active; unique offering+student|
|practicum_sessions|offering_id label weekday start_time end_time room capacity responsible_user_id|
|session_memberships|enrollment_id session_id valid_from valid_until; hanya satu membership aktif pada satu waktu|
|meetings|offering_id number title; unique offering+number|
|session_meetings|session_id meeting_id starts_at ends_at room status; unique pair|
|meeting_participants|session_meeting_id enrollment_id source/approved_request_id print_order; snapshot; unique pair|
|attendances|participant_id unique status note recorded_by version|
|attendance_documents|session_meeting_id private_file_id uploaded_by|
|materials|meeting_id title private/public file reference published_at|
|assignments|offering_id origin_meeting_id(nullable akhir) type(pendahuluan/aktivitas/lab/final/custom) mode(print/digital/direct) title instructions active|
|assignment_schedules|assignment_id session_id collection_session_meeting_id opens_at due_at closes_at allow_late upload_rules|
|submissions|assignment_id enrollment_id mode received_at recorded_at receiver_id status token_hash version; unique assignment+enrollment untuk aggregate|
|submission_versions|submission_id version_number submitted_at file/link revision_note; unique submission+version|
|report_checklist_items|assignment_id key label sort_order|
|report_checklist_results|submission_id item_id completed checked_by; unique pair|
|grading_components|offering_id assignment_id label weight nullable max_score mandatory active|
|grading_rules|offering_id version config_json status; pembulatan, batas huruf/lulus, remidi configurable|
|grades|enrollment_id component_id score nullable status evaluator_id note version; unique enrollment+component|
|final_results|enrollment_id rules_version score letter decision finalized_by finalized_at; history version|
|requests|offering_id enrollment_id type status reason evidence_file_id source/target_session_id source/target_session_meeting_id effective_date decision_by decision_at decision_note token_hash|
|remedial_attempts|enrollment_id component_id/assessment_id original_grade_id attempt score decision evaluator_id performed_at|
|announcements|offering_id title body published_at audience|
|files|storage_path original_name mime size visibility uploaded_by nullable checksum|
|activity_logs|actor_id nullable offering_id entity_type/id action before_json after_json reason created_at request_id; read-only UI|
|backup_runs|started_at finished_at status artifact_ref checksum error_summary initiated_by; bukan password|
|settings|key unique value_json; tidak menyimpan rahasia di UI/log|

## Integritas
Semua FK lintas sesi/pertemuan/enrollment harus cocok offering, validasi server dan constraint bila memungkinkan. Uniqueness submission tidak menolak versi ulang; versi berada di child table. Direct lab tidak membutuhkan submissions. Checklist laporan akhir tidak membuat 5 grade tambahan otomatis.
Grade audit ditulis atomik dengan perubahan. Token hanya hash; secret disampaikan sekali. Backup tidak masuk private academic file store agar tidak ikut rekursif.
Laravel migrations/sessions/jobs/password_reset_tokens dapat ditambahkan, hindari collision nama sessions dengan sesi praktikum.

## Revisi model dosen pembimbing (menggantikan supervisor_name pada baseline)
Gunakan master supervisors(id, name, identity_code nullable unique, active, timestamps) dan enrollments.supervisor_id nullable FK supervisors. Nama bukan identifier unik karena mungkin dosen bernama sama; gunakan identity_code jika tersedia atau identifikasi admin. Field bebas supervisor_name baseline digantikan FK, jangan menyimpan dua sumber kebenaran. supervisors 1:N enrollments; satu enrollment maksimal satu dosen saat ini. Historinya di activity_logs. NIDN/kode bukan wajib agar bisa diisi belakangan. Bulk update hanya enrollment offering berizin; nullable boleh saat impor, field kosong tidak menimpa assignment existing secara diam-diam.
