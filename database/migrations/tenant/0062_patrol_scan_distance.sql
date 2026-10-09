-- Un GPS que salta (o un celular en otra ciudad) da distancias de miles de km: DECIMAL(8,2) llegaba solo a 1.000 km
-- y el escaneo se rechazaba con un error de base. MODIFY es idempotente.
ALTER TABLE patrol_scans MODIFY distance_m DECIMAL(11,2) NULL, MODIFY accuracy_m DECIMAL(11,2) NULL;
