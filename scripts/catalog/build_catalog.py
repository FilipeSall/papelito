#!/usr/bin/env python3
"""Build a clean JSON catalog from the messy XLSX for WP/WC import."""
import json, openpyxl, re, os

def parse_price(v):
    if v is None or v == "" or v == "-":
        return None
    if isinstance(v, (int, float)):
        return float(v)
    s = str(v).strip()
    s = s.replace("R$", "").replace(" ", "").replace(".", "").replace(",", ".")
    try:
        return float(s)
    except ValueError:
        return None

def parse_num(v):
    if v is None or v == "" or v == "-":
        return None
    try:
        return float(v)
    except (TypeError, ValueError):
        return None

def slugify(s):
    s = s.lower().strip()
    s = re.sub(r"[áàâã]", "a", s)
    s = re.sub(r"[éèê]", "e", s)
    s = re.sub(r"[íì]", "i", s)
    s = re.sub(r"[óòôõ]", "o", s)
    s = re.sub(r"[úù]", "u", s)
    s = re.sub(r"ç", "c", s)
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return s.strip("-")

XLSX_PATH = os.environ.get("PAPELITO_XLSX", "/tmp/catalogo.xlsx")
OUTPUT_PATH = os.environ.get("PAPELITO_OUTPUT", "/tmp/catalog.json")
IMG_LOCAL_DIR = os.environ.get(
    "PAPELITO_IMG_LOCAL_DIR",
    "/home/sea/projetos/papelito/papelito-web/public/images/products",
)
IMG_BASE = os.environ.get(
    "PAPELITO_IMG_REMOTE_BASE",
    "/var/www/html/wp-content/uploads/papelito-import",
)

images = {}
for root, _, files in os.walk(IMG_LOCAL_DIR):
    for f in files:
        rel = os.path.relpath(os.path.join(root, f), IMG_LOCAL_DIR)
        images[f] = f"{IMG_BASE}/{rel}"

# Manual mapping product_name (normalized) → image filename
IMAGE_MAP = {
    "seda tradicional king size": "SEDA DISPLAY TRADICIONAL KS 25.png",
    "seda tradicional mini size": "SEDA DISPLAY TRADICIONAL MS.png",
    "seda tradicional com piteira": "SEDA DISPLAY TRADICIONAL COM PITEIRA.png",
    "seda tradicional longa": "SEDA DISPLAY TRADICIONAL LONGA.png",
    "seda brown king size": "SEDA DISPLAY BROWN KS 25.png",
    "seda brown mini size": "SEDA DISPLAY BROWN MS.png",
    "seda brown com piteira": "SEDA DISPLAY BROWN COM PITEIRA.png",
    "seda brown longa": "SEDA DISPLAY BROWN LONGA.png",
    "seda slim king size": "SEDA DISPLAY SLIM KS 50.png",
    "seda slim mini size": "SEDA DISPLAY SLIM MS.png",
    "seda slim com piteira": "SEDA DISPLAY SLIM COM PITEIRA.png",
    "seda slim longa": "SEDA DISPLAY SLIM LONGA.png",
    "seda hemp king size": "SEDA DISPLAY HEMP KS 25.png",
    "seda hemp mini size": "SEDA DISPLAY HEMP MS.png",
    "seda brown slim king size": "SEDA DISPLAY BROWN SLIM KS 50.png",
    "seda brown slim mini size": "SEDA DISPLAY BROWN SLIM MS.png",
    "seda insane king size": "SEDA DISPLAY INSANE WHITE.png",
    "seda insane brown king size": "SEDA DISPLAY INSANE BROWN.png",
    "seda pink king size": "SEDA DISPLAY PINK KS 50.png",
    "seda alfafa king size": "SEDA DISPLAY ALFAFA KS 50.png",
    "piteira tradicional": "PITEIRA PITEIRA TRADICIONAL.png",
    "piteira slim": "PITEIRA PITEIRA SLIM.png",
    "piteira large": "PITEIRA PITEIRA LARGE.png",
    "piteira longa": "PITEIRA PITEIRA LONGA.png",
    "piteira ultra longa": "PITEIRA PITEIRA ULTRA LONGA.png",
    "piteira mega longa": "PITEIRA PITEIRA MEGA LONGA.png",
    "filtro tradicional": "FILTRO DISPLAY TRADICIONAL.png",
    "filtro longo": "FILTRO DISPLAY TRADICIONAL LONGO.png",
    "filtro ultra longo": "FILTRO DISPLAY TRADICIONAL ULTRA LONGO.png",
    "filtro gomado": "FILTRO DISPLAY TRADICIONAL LONGO GOMADO.png",
    "filtro slim": "FILTRO DISPLAY SLIM.png",
    "filtro slim longo": "FILTRO DISPLAY SLIM LONGO.png",
    "filtro mentol": "FILTRO DISPLAY MENTOLADO.png",
    "filtro bio": "FILTRO DISPLAY BIO.png",
    "filtro bio longo": "FILTRO DISPLAY BIO LONGO.png",
}

wb = openpyxl.load_workbook(XLSX_PATH, data_only=True)
ws = wb["Produtos"]
rows = list(ws.iter_rows(values_only=True))

# Categories with subcategory mapping. Front uses slugs: sedas, piteiras, filtros, acessorios.
# Spreadsheet "Papel" → "Sedas"
CAT_MAP = {
    "Papel": "Sedas",
    "Piteiras": "Piteiras",
    "Filtro": "Filtros",
    "Acessórios": "Acessórios",
}

simple_products = []
variable_groups = {}  # key=group_name → {category, subcat, description, sku_base, variants:[(color,sku,raw_g,raw_j)]}
draft_products = []
seen_skus = set()

# Skip header rows 1-2; data starts row 3
for i, r in enumerate(rows[2:], start=3):
    if not r or not r[0]:
        continue
    name = str(r[0]).strip()
    cat_raw = (r[1] or "").strip()
    subcat = (r[2] or "").strip()
    subcat2 = (r[3] or "").strip()
    color = (r[4] or "").strip()
    desc = (r[5] or "").strip()
    sku_raw = r[6]
    price_raw = r[9]  # column J
    altura = parse_num(r[10])
    largura = parse_num(r[11])
    comprimento = parse_num(r[12])
    peso_bruto = parse_num(r[13])
    peso_liq = parse_num(r[14])

    if cat_raw not in CAT_MAP:
        continue
    category = CAT_MAP[cat_raw]

    img_key = name.lower().strip()
    img_file = IMAGE_MAP.get(img_key)
    img_path = images.get(img_file) if img_file else None

    sku = str(sku_raw).strip() if sku_raw else ""
    if sku and re.match(r"^\d+(\.\d+)?$", sku):
        # Numeric SKU = data shifted (accessory rows). Drop SKU.
        sku = ""
    if sku == "-":
        sku = ""

    price = parse_price(price_raw)
    # If accessory row: price might be in col G (raw_g) since columns shifted.
    if price is None and isinstance(sku_raw, (int, float)):
        # raw_g is numeric -> could be GTIN, leave price empty
        pass

    # Variable products: Dichavador, Tubelito, Bandeja Chaveiro
    is_variable = cat_raw == "Acessórios" and subcat in ("Dichavador", "Tubelito", "Bandeja Chaveiro")

    if is_variable:
        # group key = "<subcat> <subcat2>" e.g. "Dichavador Tradicional"
        group_name = f"{subcat} {subcat2}".strip() if subcat2 and subcat2 != "-" else subcat
        full_name = f"{group_name}".strip()
        gkey = full_name
        g = variable_groups.setdefault(gkey, {
            "name": full_name,
            "category": category,
            "subcategory": subcat,
            "description": desc,
            "variants": [],
            "todo": [],
        })
        # Color variant
        if color and color != "-":
            g["variants"].append({
                "color": color,
                "sku": sku or "",
                "price": price,
                "raw_g": sku_raw,
            })
        if price is None:
            g["todo"].append(f"Preço da cor {color or 'única'} ausente — coluna G='{sku_raw}', coluna J='{price_raw}'")
        continue

    incomplete = (price is None) or (not sku)
    todo_notes = []
    if price is None:
        todo_notes.append(f"Preço ausente (planilha col J='{price_raw}', col G='{sku_raw}')")
    if not sku:
        todo_notes.append("SKU ausente — preencher manualmente")

    # SKU uniqueness
    if sku and sku in seen_skus:
        todo_notes.append(f"SKU original '{sku}' duplicado em outro produto — gerado SKU alternativo")
        sku = f"{sku}-{slugify(name)[:10]}"
    if sku:
        seen_skus.add(sku)

    prod = {
        "name": name,
        "slug": slugify(name),
        "category": category,
        "subcategory": subcat if subcat and subcat != "-" else None,
        "description": desc,
        "sku": sku,
        "price": price,
        "altura_cm": altura,
        "largura_cm": largura,
        "comprimento_cm": comprimento,
        "peso_bruto_kg": peso_bruto,
        "peso_liq_kg": peso_liq,
        "image_path": img_path,
        "image_file": img_file,
        "todo": todo_notes,
        "status": "draft" if incomplete else "publish",
    }
    if incomplete:
        draft_products.append(prod)
    else:
        simple_products.append(prod)

# Add products that exist only in 'Site' sheet but not in Produtos: Cinzeiro, Bandeja P/M/G
ws_site = wb["Site"]
site_rows = list(ws_site.iter_rows(values_only=True))
existing_names_norm = {p["name"].lower() for p in simple_products + draft_products}
for vg in variable_groups.values():
    existing_names_norm.add(vg["name"].lower())

site_extra = []
for r in site_rows[2:]:
    if not r or not r[0]:
        continue
    cat = (r[0] or "").strip()
    name = (r[1] or "").strip()
    desc = (r[3] or "").strip()
    size = (r[4] or "").strip()
    if cat != "Acessórios":
        continue
    if name.lower() in existing_names_norm:
        continue
    if name.lower() in ("dichavador", "tubelito", "bandeja chaveiro"):
        continue
    full = f"{name}"
    if name.lower() in existing_names_norm:
        continue
    full_desc = f"{desc}\n\nTamanho: {size}" if desc else ""
    site_extra.append({
        "name": full,
        "slug": slugify(full),
        "category": "Acessórios",
        "subcategory": name,
        "description": full_desc,
        "sku": "",
        "price": None,
        "image_path": None,
        "image_file": None,
        "todo": ["Produto da aba Site — sem dados em Produtos. Preencher SKU, preço, peso, dimensões, fotos."],
        "status": "draft",
        "altura_cm": None, "largura_cm": None, "comprimento_cm": None,
        "peso_bruto_kg": None, "peso_liq_kg": None,
    })

# For Bandeja Chaveiro: turn into a variable group since spreadsheet has 3 rows in Produtos
# Already collected above via is_variable logic.

# Convert variable_groups to list. Groups with 0 variants → drafts (simple).
variable_products = []
for g in variable_groups.values():
    prices = [v["price"] for v in g["variants"] if v["price"] is not None]
    g["price"] = max(prices) if prices else None
    g["image_path"] = None
    g["image_file"] = None
    if len(g["variants"]) <= 1:
        catalog_draft = {
            "name": g["name"],
            "slug": slugify(g["name"]),
            "category": g["category"],
            "subcategory": g["subcategory"],
            "description": g["description"],
            "sku": "",
            "price": g["price"],
            "altura_cm": None, "largura_cm": None, "comprimento_cm": None,
            "peso_bruto_kg": None, "peso_liq_kg": None,
            "image_path": None, "image_file": None,
            "todo": (g.get("todo") or []) + ["Acessório sem variações na planilha — preencher SKU/peso/dimensões."],
            "status": "draft",
        }
        catalog_draft["price"] = g["price"]
        draft_products.append(catalog_draft)
    else:
        variable_products.append(g)

catalog = {
    "simple": simple_products,
    "variable": variable_products,
    "draft": draft_products + site_extra,
}

with open(OUTPUT_PATH, "w") as f:
    json.dump(catalog, f, ensure_ascii=False, indent=2)

print(f"simple: {len(simple_products)}")
print(f"variable groups: {len(variable_products)}")
print(f"draft (incomplete): {len(catalog['draft'])}")
print(f"images mapped: {sum(1 for p in simple_products + draft_products if p.get('image_path'))}")
