# Sertifikat CA Aiven

Taruh file **`aiven-ca.pem`** di folder ini, lalu commit.
Unduh dari Aiven Console -> service MySQL -> Overview -> **CA certificate**.

Di container file ini berada di `/app/docker/aiven-ca.pem` (sudah menjadi default `MYSQL_ATTR_SSL_CA` di `Dockerfile.vercel`).
Sertifikat CA bersifat publik, aman di-commit. Jangan commit password/URI database.
