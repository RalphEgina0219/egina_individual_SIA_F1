# CRM – Team Integration (API v1)

Ang CRM (Customer Management, Member 1) ay isa sa 4 na system ng grupo: **CRM**, **PROC**, **INV** at **HR**.
Ang patakaran ng grupo ay nasa `BAHAGI-B-C-STANDARD.md` (Bahagi B at C). Ang file na ito ay para sa CRM lang.

- **Base URL:** `http://<CRM Elastic IP>:8000/api/integration/v1`
- **Security:** header na `X-API-KEY: <INTEGRATION_API_KEY ng CRM>`. Mali o walang key: `401`. Ang `/health` lang ang walang key.
- **Mga petsa:** ISO 8601 na may `+08:00`. **Pera:** string na may 2 decimals (`"320.00"`). **Quantity:** integer.
- **Mga listahan:** `?per_page=` (default 100) at `?page=`. Ganito ang sagot: `"data": {"items": [...], "meta": {"current_page":1,"last_page":1,"total":9}}`.

---

## 1. Mga endpoint na ibinibigay ng CRM

### GET /health (walang key)
```bash
curl http://<CRM_IP>:8000/api/integration/v1/health
```
```json
{"success":true,"system":"CRM","version":"v1","time":"2026-11-25T01:41:45+08:00"}
```

### GET /staff-performance?period=YYYY-MM (F8, para sa HR)
Bawat `employee_no` sa buwang iyon (oras ng Pilipinas):

| Field | Paano kinukuwenta |
|---|---|
| `total_sales` | Kabuuan ng benta na hindi Cancelled, kaya net na ito ng cancelled. Ang cashier ay ang `orders.cashier_employee_no`; sa lumang benta, ang `employee_no` ng user na nagbenta |
| `transaction_count` | Bilang ng mga benta na iyon |
| `complaint_count` | Bilang ng complaint (Complaint, Product Concern, Service Concern) kung saan siya ang "Staff involved" |
| `average_rating` | Average na rating ng feedback na may kanyang `employee_no`; `null` kung wala |

```bash
curl -H "X-API-KEY: <key>" "http://<CRM_IP>:8000/api/integration/v1/staff-performance?period=2026-11"
```
```json
{"success":true,"message":"OK","data":{"items":[
  {"employee_no":"EMP-0003","period":"2026-11","total_sales":"15250.00","transaction_count":42,"complaint_count":1,"average_rating":"4.60"}
],"meta":{"current_page":1,"last_page":1,"total":1}}}
```
Kapag mali ang period (hal. `2026-13`):
```json
{"success":false,"message":"Validation failed","errors":{"period":["The period must be in YYYY-MM format, e.g. 2026-11."]}}
```
HTTP `422` ang status nito.

### GET /product-sales?period=YYYY-MM
Bawat `sku` (SKU-xxxx lang): `quantity_sold`, `total_sales`, `transaction_count`.
```json
{"success":true,"message":"OK","data":{"items":[
  {"sku":"SKU-0001","period":"2026-11","quantity_sold":3,"total_sales":"960.00","transaction_count":1}
],"meta":{"current_page":1,"last_page":1,"total":1}}}
```

### GET /customers/{customer_code}
Pangunahing impormasyon lang. Walang email, phone, address o birthday.
```json
{"success":true,"message":"OK","data":{"customer_code":"CUST-000001","full_name":"Maria Santos",
 "membership_level":"Silver","loyalty_points":120,"status":"Active","updated_at":"2026-11-23T09:00:00+08:00"}}
```
Kapag walang ganoong customer:
```json
{"success":false,"message":"Customer not found","errors":{"customer_code":["No customer with code CUST-999999."]}}
```
HTTP `404` ang status nito.

---

## 2. Mga ipinapadala ng CRM (sa outbox)

Hindi direktang tumatawag ang CRM habang ginagawa ng user ang benta o reklamo. Ganito ang nangyayari:
1. Sa parehong database transaction, naglalagay ang CRM ng row sa `integration_outbox`.
2. Ang `scheduler` container ang nagpapadala nito bawat minuto (`integration:process-outbox`).
3. Kapag down ang ibang system, tuloy pa rin ang benta, at ipapadala ang request pagbalik ng system.

| # | Kailan | Papunta | Body (B9) | `reference_number` |
|---|---|---|---|---|
| F3 | Bagong benta | `POST {INV}/stock-movements` | `SALE_OUT`, `branch_code` ng branch, `performed_by` = cashier, `items` = sku + quantity | `TXN-20261123-000045` |
| F3 | Na-cancel ang benta | `POST {INV}/stock-movements` | `SALE_RETURN` na may parehong items | `TXN-20261123-000045-RET` |
| F7 | Pinindot ang "Forward to Procurement" | `POST {PROC}/supplier-issues` | sku, issue_type, description, quantity_affected, branch_code, reported_at | `FB-20261123-0012` |

**Mga patakaran:**
- **Retry:** Kapag nag-fail, susubukan ulit pagkalipas ng 1, 2, 4 at 8 minuto. `failed` na ito pagdating ng 5 attempts. Kapag `422` ang sagot, `failed` agad.
- **`INTEGRATION_ENABLED=false`:** walang ipapadala, at mananatiling `pending` ang mga row.
- **Walang SKU-xxxx ang produkto:** hindi pinipigilan ang benta. Nagiging `failed` ang `orders.stock_sync_status`, at may log ito sa Integrations page.
- **Ang SALE_RETURN:** ipinapadala lang pagkatapos maipadala ang SALE_OUT. Kapag `failed` ang SALE_OUT, hindi na rin ipapadala ang SALE_RETURN, kasi walang naibawas na stock sa INV na kailangang ibalik.
- **Ang lumang pagbawas ng stock ng CRM:** hindi ginalaw. Dagdag lang ang pagpapadala sa INV.

**Mga status:**
- `orders.stock_sync_status`: `not_sent` → `pending` → `synced`, o `failed`.
- `customer_feedback.forward_status`: `not_forwarded` → `pending` → `sent`, o `failed`. Kapag `sent` na, naitatala rin ang `supplier_issue_number` (SI-...) na isinagot ng PROC.

---

## 3. Mga kinukuha ng CRM (Yugto 2): `integration:sync-masterdata`

Tumatakbo ito bawat 15 minuto, gamit ang `?updated_since=`. Walang ginagawa ito kapag `INTEGRATION_ENABLED=false`.

| Galing | Endpoint | Ano ang ina-update sa CRM |
|---|---|---|
| HR | `/employees` | Ang table na `employees` (kopya), pati ang `users` na may parehong `employee_no`: status, `can_login`, branch, at CRM role. Walang bagong user na ginagawa. Kapag `can_login=false`, binubura ang mga token niya |
| HR | `/branches` | `branches` (gamit ang code): pangalan, city, active |
| INV | `/products` | `products` (gamit ang sku): pangalan, kategorya, presyo, status. Hindi ginagalaw ang lokal na stock |
| INV | `/warehouses` | `warehouses` (gamit ang warehouse_code) |
| INV | `/stock` | `products.inv_stock`, na makikita bilang "INV stock" sa product page (pang-display lang) |

---

## 4. Mga command

Patakbuhin ito sa loob ng `app` container, bilang `www-data`. Kapag root ang nagpatakbo, puwedeng mag-iwan ito ng log file na hindi masulatan ng web server.

```bash
# Ipakita muna kung paano itutugma ang branches, warehouses, products at users sa mga code
docker compose exec -u www-data app php artisan integration:map-master-data --dry-run
# I-save (nagtatanong muna; idagdag ang --force para hindi na magtanong)
docker compose exec -u www-data app php artisan integration:map-master-data --force

# Kopya ng employees galing sa employees.csv (upsert lang, ligtas ulitin)
docker compose exec -u www-data app php artisan db:seed --class=MasterDataSeeder --force

# Manual na pagpapadala ng outbox, at pagkuha ng mga kopya
docker compose exec -u www-data app php artisan integration:process-outbox
docker compose exec -u www-data app php artisan integration:sync-masterdata --full
```

**Ang mapping file:** `database/seeders/data/crm_mapping.csv`. Kapag may branch, warehouse, produkto o user na hindi tugma (lalabas ito bilang babala sa `--dry-run`), idagdag ito rito at patakbuhin ulit ang command.

---

## 5. Settings → Integrations (Admin lang)

Ang laman ng page:
- Health ng CRM, PROC, INV at HR (green/red/gray).
- Ang outbox, na may filter na status at "Retry" button para sa mga `failed`.
- Ang "Send pending now" button.
- Ang mga benta at reklamo na `failed`.
- Ang huling 100 na integration logs.

---

## 6. Deploy sa AWS (EC2, `~/app`)

> **Mahalaga:** Kusang nagpapatakbo ng `migrate` ang `app` container tuwing nagsisimula ito. Kaya **mag-backup muna bago** ang `git pull` at `up --build`.

```bash
ssh -4 sales
cd ~/app
grep PUBLIC_HOST .env                         # dapat may laman; kung wala, masisira ang login ng live site

# 1. Backup ng database
sudo docker compose exec -T db sh -c 'mysqldump -u root -proot_password --single-transaction --no-tablespaces sales_db' > ~/backup-$(date +%F-%H%M).sql
ls -lh ~/backup-*.sql                          # dapat hindi 0 ang laki

# 2. Idagdag ang mga variable sa B6 sa ~/app/.env (isang beses lang)
#    SYSTEM_CODE=CRM
#    INTEGRATION_ENABLED=false
#    INTEGRATION_API_KEY=<gawa gamit ang: openssl rand -hex 32>
#    CRM_API_URL= / CRM_API_KEY= / PROC_... / INV_... / HR_...   (walang laman muna)

# 3. Kunin ang code at i-build (kusang nagma-migrate)
git pull
sudo docker compose up -d --build
sudo docker compose ps                          # dapat Up ang app, scheduler, db at frontend
sudo docker compose exec -u www-data app php artisan migrate --force   # "Nothing to migrate" kung tapos na

# 4. Itugma ang data sa mga code ng grupo
sudo docker compose exec -u www-data app php artisan integration:map-master-data --dry-run
sudo docker compose exec -u www-data app php artisan integration:map-master-data --force
sudo docker compose exec -u www-data app php artisan db:seed --class=MasterDataSeeder --force

# 5. I-check
curl http://<IP>:8000/api/integration/v1/health
curl -H "X-API-KEY: <key>" "http://<IP>:8000/api/integration/v1/staff-performance?period=2026-11"
```

Pagkatapos:
- Ibigay sa grupo ang `CRM_API_URL=http://<IP>:8000/api/integration/v1`.
- Ang key ay ibigay **sa pribadong mensahe lang**.

**Kung may masira, ibalik ang database:**
```bash
sudo docker compose exec -T db sh -c 'mysql -u root -proot_password sales_db' < ~/backup-<petsa>.sql
```

---

## 7. Yugto 2 (kapag handa na ang lahat)

1. Ilagay sa `~/app/.env` ang `PROC_API_URL/KEY`, `INV_API_URL/KEY` at `HR_API_URL/KEY`, tapos `INTEGRATION_ENABLED=true`.
2. Patakbuhin ang `sudo docker compose up -d`. Hindi na kailangan ng `--build`, kasi `.env` lang ang nagbago.
3. Patakbuhin ang `sudo docker compose exec -u www-data app php artisan integration:sync-masterdata --full`.
4. Buksan ang Settings → Integrations. Dapat green ang 4 na system.

**Para sa end-to-end test (B10):**
- **Hakbang 3:** may sariling stock pa rin ang CRM. Kaya bago magbenta ng SKU-0050, lagyan muna ito ng stock sa **Inventory** page ng CRM (lalabas ito sa CRM pagkatapos ng sync).
- **Hakbang 1:** lalabas si EMP-0010 sa table na `employees` ng CRM. Para makapag-login siya sa CRM, gawan siya ng account sa Settings → User Management, na may Employee No. na `EMP-0010`.

---

## 8. Mga pagkakaiba sa standard (sa loob lang ng CRM; walang epekto sa ibang system)

| Standard (B3) | CRM | Bakit |
|---|---|---|
| Service `api` | Service `app` | Live na ang site. Kapag pinalitan ang pangalan, maiiwang tumatakbo ang lumang container sa port 8000 |
| PHP 8.3 | PHP 8.2 | Ito ang image na tumatakbo sa live; papalitan kapag may oras na i-test |
| `APP_TIMEZONE=Asia/Manila` | UTC ang loob ng database | Kapag pinalitan, mali nang 8 oras ang lahat ng lumang petsa. Lahat ng petsang lumalabas sa API ay `+08:00` pa rin |
| `feedback_number` lang | May lumang `reference_number` (FB-2026-000001) pa rin | Para hindi masira ang dating mga ulat; ang `feedback_number` (FB-YYYYMMDD-0001) ang ipinapadala sa ibang system |
