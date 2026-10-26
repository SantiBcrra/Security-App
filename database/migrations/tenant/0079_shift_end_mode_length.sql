-- "sin_persona" (dejar el puesto sin guardia con motivo) y "relevo" no entraban en end_mode. Idempotente.
ALTER TABLE guard_shift_slots MODIFY end_mode VARCHAR(12) NULL;
