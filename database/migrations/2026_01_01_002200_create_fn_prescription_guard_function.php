<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Installed once in public; the Prescription module's tenant migrations attach the triggers (SCHEMA §5.3.3).
// The child branch resolves `prescriptions` through the tenant search_path, so the body must not schema-qualify it.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.fn_prescription_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
  IF TG_TABLE_NAME = 'prescriptions' THEN
    IF OLD.status <> 'draft' THEN
      IF NEW.status NOT IN ('issued','amended','voided') OR NEW.snapshot IS DISTINCT FROM OLD.snapshot
         OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256 OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
         OR NEW.verification_code IS DISTINCT FROM OLD.verification_code OR NEW.version <> OLD.version
         OR NEW.visit_id <> OLD.visit_id OR NEW.patient_id <> OLD.patient_id OR NEW.doctor_id <> OLD.doctor_id
         OR NEW.handwriting_image_path IS DISTINCT FROM OLD.handwriting_image_path
         OR NEW.drawing_json IS DISTINCT FROM OLD.drawing_json THEN
        RAISE EXCEPTION 'prescription % is immutable (status %)', OLD.id, OLD.status USING ERRCODE = 'check_violation';
      END IF;
    END IF;
    RETURN NEW;
  END IF;
  SELECT status INTO parent_status FROM prescriptions WHERE id = COALESCE(NEW.prescription_id, OLD.prescription_id);
  IF parent_status IS DISTINCT FROM 'draft' THEN
    RAISE EXCEPTION 'prescription % children are immutable', COALESCE(NEW.prescription_id, OLD.prescription_id) USING ERRCODE = 'check_violation';
  END IF;
  RETURN COALESCE(NEW, OLD);
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.fn_prescription_guard()');
    }
};
