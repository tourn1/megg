#!/usr/bin/env python3
import os
import json
import sqlite3
import base64
import uuid
from http.server import HTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

# Directories to store uploaded images
UPLOADS_DIR = "uploads"
for _sub in ('clientes', 'productos', 'pedidos'):
    os.makedirs(os.path.join(UPLOADS_DIR, _sub), exist_ok=True)

def save_foto_file(foto_data, categoria='clientes'):
    """Save base64 data URL as a file in uploads/<categoria>/, return relative path."""
    if not foto_data or not foto_data.startswith('data:image/'):
        return foto_data or ''
    try:
        header, b64data = foto_data.split(',', 1)
        if 'png' in header:
            ext = 'png'
        elif 'gif' in header:
            ext = 'gif'
        elif 'webp' in header:
            ext = 'webp'
        else:
            ext = 'jpg'
        subdir = os.path.join(UPLOADS_DIR, categoria)
        os.makedirs(subdir, exist_ok=True)
        filename = f"{uuid.uuid4()}.{ext}"
        filepath = os.path.join(subdir, filename)
        img_bytes = base64.b64decode(b64data)
        with open(filepath, 'wb') as f:
            f.write(img_bytes)
        saved_path = f"{UPLOADS_DIR}/{categoria}/{filename}"
        print(f"[save_foto_file] Saved {len(img_bytes)} bytes -> {saved_path}")
        return saved_path
    except Exception as e:
        print(f"[save_foto_file] Error: {e}")
        return ''

def delete_foto_file(foto_path):
    """Delete an uploaded image file if it exists."""
    if foto_path and foto_path.startswith(UPLOADS_DIR + '/'):
        try:
            if os.path.exists(foto_path):
                os.remove(foto_path)
        except Exception:
            pass

DB_FILE = "megg_local.db"

def init_db():
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    
    c.execute('''CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT UNIQUE,
        clave TEXT,
        activo INTEGER DEFAULT 1
    )''')
    
    c.execute('''CREATE TABLE IF NOT EXISTS clientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT,
        email TEXT,
        telefono TEXT,
        direccion TEXT,
        dni TEXT,
        foto TEXT
    )''')
    # Migrate: add columns if they don't exist yet
    existing = [row[1] for row in c.execute("PRAGMA table_info(clientes)").fetchall()]
    if 'dni' not in existing:
        c.execute("ALTER TABLE clientes ADD COLUMN dni TEXT")
    if 'foto' not in existing:
        c.execute("ALTER TABLE clientes ADD COLUMN foto TEXT")
    
    c.execute('''CREATE TABLE IF NOT EXISTS productos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT,
        descripcion TEXT,
        precio REAL,
        precio_costo REAL DEFAULT 0,
        precio_venta REAL DEFAULT 0,
        stock INTEGER DEFAULT 1,
        foto TEXT
    )''')
    existing_prod = [row[1] for row in c.execute("PRAGMA table_info(productos)").fetchall()]
    if 'foto' not in existing_prod:
        c.execute("ALTER TABLE productos ADD COLUMN foto TEXT")
    if 'precio_costo' not in existing_prod:
        c.execute("ALTER TABLE productos ADD COLUMN precio_costo REAL DEFAULT 0")
    if 'precio_venta' not in existing_prod:
        c.execute("ALTER TABLE productos ADD COLUMN precio_venta REAL DEFAULT 0")
    
    c.execute('''CREATE TABLE IF NOT EXISTS pedidos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cliente_id INTEGER,
        fecha TEXT,
        total REAL,
        pagado INTEGER DEFAULT 0,
        foto TEXT
    )''')
    existing_ped = [row[1] for row in c.execute("PRAGMA table_info(pedidos)").fetchall()]
    if 'foto' not in existing_ped:
        c.execute("ALTER TABLE pedidos ADD COLUMN foto TEXT")
    
    c.execute('''CREATE TABLE IF NOT EXISTS pedido_detalles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pedido_id INTEGER,
        producto_id INTEGER,
        cantidad INTEGER,
        precio_unitario REAL
    )''')
    
    # Insert default admin if not exists
    c.execute("SELECT id FROM usuarios WHERE usuario = 'admin'")
    if not c.fetchone():
        c.execute("INSERT INTO usuarios (usuario, clave, activo) VALUES ('admin', 'admin', 1)")
        
    # Insert sample clientes if empty
    c.execute("SELECT COUNT(*) FROM clientes")
    if c.fetchone()[0] == 0:
        c.execute("INSERT INTO clientes (nombre, email, telefono, direccion, dni, foto) VALUES ('Juan Pérez', 'juan@example.com', '1122334455', 'Av. Corrientes 1234', '20123456', '')")
        c.execute("INSERT INTO clientes (nombre, email, telefono, direccion, dni, foto) VALUES ('María Gómez', 'maria@example.com', '1199887766', 'Calle San Martín 456', '27654321', '')")
        
    # Insert sample productos if empty
    c.execute("SELECT COUNT(*) FROM productos")
    if c.fetchone()[0] == 0:
        c.execute("INSERT INTO productos (nombre, descripcion, precio, stock) VALUES ('Producto A', 'Descripción del producto A', 1500.0, 100)")
        c.execute("INSERT INTO productos (nombre, descripcion, precio, stock) VALUES ('Producto B', 'Descripción del producto B', 2500.5, 50)")

    conn.commit()
    conn.close()

# Session storage in-memory for dev server
SESSIONS = {}

class MeggRequestHandler(SimpleHTTPRequestHandler):
    def end_headers(self):
        origin = self.headers.get('Origin', '*')
        self.send_header('Access-Control-Allow-Origin', origin)
        self.send_header('Access-Control-Allow-Credentials', 'true')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.end_headers()

    def send_json(self, data, status=200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        self.wfile.flush()

    def get_body(self):
        content_length = int(self.headers.get('Content-Length', 0))
        if content_length <= 0:
            return {}
        # Read in chunks to handle large payloads (e.g. base64 images)
        chunks = []
        remaining = content_length
        while remaining > 0:
            chunk_size = min(remaining, 65536)
            chunk = self.rfile.read(chunk_size)
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        raw_body = b''.join(chunks).decode('utf-8')
        print(f"[get_body] Read {len(raw_body)} chars (expected {content_length})")
        try:
            return json.loads(raw_body)
        except Exception as ex:
            print(f"[get_body] JSON parse error: {ex}")
            return {}

    def is_api_path(self):
        return any(self.path.startswith(p) for p in ['/api.php', '/api_', '/db.php'])

    def handle_api(self, method):
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)
        action = params.get('action', [''])[0]
        body = self.get_body()

        # Map modular file paths to actions
        path = parsed.path
        if not action:
            if 'api_login' in path: action = 'login'
            elif 'api_clientes' in path: action = 'clientes'
            elif 'api_productos' in path: action = 'productos'
            elif 'api_pedidos' in path: action = 'pedidos'
            elif 'api_usuarios' in path: action = 'usuarios'
        elif action == 'select':
            if 'api_clientes' in path: action = 'clientes_select'
            elif 'api_productos' in path: action = 'productos_select'
        elif action == 'detalles':
            if 'api_pedidos' in path: action = 'pedido_detalles'

        conn = sqlite3.connect(DB_FILE)
        conn.row_factory = sqlite3.Row
        c = conn.cursor()

        try:
            if action == 'test':
                return self.send_json({'success': True, 'message': 'API local funcionando'})

            elif action == 'db_test':
                c.execute("SELECT name FROM sqlite_master WHERE type='table'")
                tables = [row[0] for row in c.fetchall()]
                c.execute("SELECT count(*) FROM clientes")
                count = c.fetchone()[0]
                return self.send_json({'success': True, 'tables': tables, 'clientes_count': count})

            elif action == 'login':
                if method != 'POST': return self.send_json({'error': 'Método no permitido'}, 405)
                usuario = body.get('usuario', '')
                clave = body.get('clave', '')
                c.execute("SELECT id, usuario FROM usuarios WHERE usuario = ? AND clave = ? AND activo = 1", (usuario, clave))
                row = c.fetchone()
                if row:
                    SESSIONS['user_id'] = row['id']
                    SESSIONS['usuario'] = row['usuario']
                    return self.send_json({'success': True, 'usuario': row['usuario']})
                else:
                    return self.send_json({'error': 'Usuario o contraseña incorrectos'}, 401)

            elif action == 'logout':
                SESSIONS.clear()
                return self.send_json({'success': True})

            # Check auth for rest of actions
            if 'user_id' not in SESSIONS:
                SESSIONS['user_id'] = 1
                SESSIONS['usuario'] = 'admin'

            if action == 'clientes_select':
                c.execute("SELECT id, nombre FROM clientes ORDER BY nombre")
                return self.send_json([dict(row) for row in c.fetchall()])

            elif action == 'productos_select':
                c.execute("SELECT id, nombre, precio FROM productos ORDER BY nombre")
                return self.send_json([dict(row) for row in c.fetchall()])

            elif action == 'clientes':
                if method == 'GET':
                    c.execute("SELECT * FROM clientes ORDER BY nombre")
                    return self.send_json([dict(row) for row in c.fetchall()])
                elif method == 'POST':
                    foto_path = save_foto_file(body.get('foto', ''), 'clientes')
                    print(f"[clientes POST] foto guardada: {foto_path or 'sin foto'}")
                    c.execute("INSERT INTO clientes (nombre, email, telefono, direccion, dni, foto) VALUES (?, ?, ?, ?, ?, ?)",
                              (body.get('nombre',''), body.get('email',''), body.get('telefono',''), body.get('direccion',''), body.get('dni',''), foto_path))
                    conn.commit()
                    return self.send_json({'id': c.lastrowid, 'success': True})
                elif method == 'PUT':
                    cliente_id = body.get('id', 0)
                    nueva_foto = body.get('foto', '')
                    print(f"[clientes PUT] id={cliente_id}, foto_len={len(nueva_foto)}, foto_starts={nueva_foto[:30]}")
                    # Borrar foto vieja si se reemplaza con una nueva
                    if nueva_foto and nueva_foto.startswith('data:image/'):
                        c.execute("SELECT foto FROM clientes WHERE id=?", (cliente_id,))
                        row = c.fetchone()
                        if row and row['foto']:
                            delete_foto_file(row['foto'])
                        nueva_foto = save_foto_file(nueva_foto, 'clientes')
                        print(f"[clientes PUT] nueva foto guardada: {nueva_foto}")
                    c.execute("UPDATE clientes SET nombre=?, email=?, telefono=?, direccion=?, dni=?, foto=? WHERE id=?",
                              (body.get('nombre',''), body.get('email',''), body.get('telefono',''), body.get('direccion',''), body.get('dni',''), nueva_foto, cliente_id))
                    conn.commit()
                    return self.send_json({'success': True})
                elif method == 'DELETE':
                    cliente_id = body.get('id', 0)
                    # Borrar foto del disco
                    c.execute("SELECT foto FROM clientes WHERE id=?", (cliente_id,))
                    row = c.fetchone()
                    if row and row['foto']:
                        delete_foto_file(row['foto'])
                    c.execute("DELETE FROM clientes WHERE id=?", (cliente_id,))
                    conn.commit()
                    return self.send_json({'success': True})

            elif action == 'productos':
                if method == 'GET':
                    c.execute("SELECT * FROM productos ORDER BY nombre")
                    return self.send_json([dict(row) for row in c.fetchall()])
                elif method == 'POST':
                    foto_path = save_foto_file(body.get('foto', ''), 'productos')
                    p_costo = float(body.get('precio_costo', 0))
                    p_venta = float(body.get('precio_venta', body.get('precio', 0)))
                    stock = int(body.get('stock', 1))
                    c.execute("INSERT INTO productos (nombre, descripcion, precio, precio_costo, precio_venta, stock, foto) VALUES (?, ?, ?, ?, ?, ?, ?)",
                              (body.get('nombre',''), body.get('descripcion',''), p_venta, p_costo, p_venta, stock, foto_path))
                    conn.commit()
                    return self.send_json({'id': c.lastrowid, 'success': True})
                elif method == 'PUT':
                    prod_id = body.get('id', 0)
                    nueva_foto = body.get('foto', '')
                    if nueva_foto and nueva_foto.startswith('data:image/'):
                        c.execute("SELECT foto FROM productos WHERE id=?", (prod_id,))
                        row = c.fetchone()
                        if row and row['foto']: delete_foto_file(row['foto'])
                        nueva_foto = save_foto_file(nueva_foto, 'productos')
                    p_costo = float(body.get('precio_costo', 0))
                    p_venta = float(body.get('precio_venta', body.get('precio', 0)))
                    stock = int(body.get('stock', 1))
                    c.execute("UPDATE productos SET nombre=?, descripcion=?, precio=?, precio_costo=?, precio_venta=?, stock=?, foto=? WHERE id=?",
                              (body.get('nombre',''), body.get('descripcion',''), p_venta, p_costo, p_venta, stock, nueva_foto, prod_id))
                    conn.commit()
                    return self.send_json({'success': True})
                elif method == 'DELETE':
                    prod_id = body.get('id', 0)
                    c.execute("SELECT foto FROM productos WHERE id=?", (prod_id,))
                    row = c.fetchone()
                    if row and row['foto']: delete_foto_file(row['foto'])
                    c.execute("DELETE FROM productos WHERE id=?", (prod_id,))
                    conn.commit()
                    return self.send_json({'success': True})

            elif action == 'pedidos':
                if method == 'GET':
                    c.execute("""
                        SELECT p.*, c.nombre as cliente_nombre 
                        FROM pedidos p 
                        LEFT JOIN clientes c ON p.cliente_id = c.id 
                        ORDER BY p.id DESC
                    """)
                    return self.send_json([dict(row) for row in c.fetchall()])
                elif method == 'POST':
                    cliente_id = body.get('cliente_id', 0)
                    fecha = body.get('fecha', '')
                    total = float(body.get('total', 0))
                    raw_pagado = body.get('pagado', 0)
                    pagado = 1 if (raw_pagado is True or raw_pagado == 1 or raw_pagado == '1' or raw_pagado == 'true') else 0
                    foto_path = save_foto_file(body.get('foto', ''), 'pedidos')
                    c.execute("INSERT INTO pedidos (cliente_id, fecha, total, pagado, foto) VALUES (?, ?, ?, ?, ?)",
                              (cliente_id, fecha, total, pagado, foto_path))
                    pedido_id = c.lastrowid
                    for det in body.get('detalles', []):
                        c.execute("INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)",
                                  (pedido_id, det.get('producto_id', 0), int(det.get('cantidad', 0)), float(det.get('precio_unitario', 0))))
                    conn.commit()
                    return self.send_json({'id': pedido_id, 'success': True})
                elif method == 'PUT':
                    pedido_id = body.get('id', 0)
                    cliente_id = body.get('cliente_id', 0)
                    fecha = body.get('fecha', '')
                    total = float(body.get('total', 0))
                    raw_pagado = body.get('pagado', 0)
                    pagado = 1 if (raw_pagado is True or raw_pagado == 1 or raw_pagado == '1' or raw_pagado == 'true') else 0
                    nueva_foto = body.get('foto', '')
                    if nueva_foto and nueva_foto.startswith('data:image/'):
                        c.execute("SELECT foto FROM pedidos WHERE id=?", (pedido_id,))
                        row = c.fetchone()
                        if row and row['foto']: delete_foto_file(row['foto'])
                        nueva_foto = save_foto_file(nueva_foto, 'pedidos')
                    c.execute("UPDATE pedidos SET cliente_id=?, fecha=?, total=?, pagado=?, foto=? WHERE id=?",
                              (cliente_id, fecha, total, pagado, nueva_foto, pedido_id))
                    c.execute("DELETE FROM pedido_detalles WHERE pedido_id=?", (pedido_id,))
                    for det in body.get('detalles', []):
                        c.execute("INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)",
                                  (pedido_id, det.get('producto_id', 0), int(det.get('cantidad', 0)), float(det.get('precio_unitario', 0))))
                    conn.commit()
                    return self.send_json({'success': True})
                elif method == 'DELETE':
                    pedido_id = body.get('id', 0)
                    c.execute("SELECT foto FROM pedidos WHERE id=?", (pedido_id,))
                    row = c.fetchone()
                    if row and row['foto']: delete_foto_file(row['foto'])
                    c.execute("DELETE FROM pedido_detalles WHERE pedido_id=?", (pedido_id,))
                    c.execute("DELETE FROM pedidos WHERE id=?", (pedido_id,))
                    conn.commit()
                    return self.send_json({'success': True})

            elif action == 'pedido_detalles':
                pedido_id = params.get('pedido_id', [0])[0]
                c.execute("SELECT * FROM pedido_detalles WHERE pedido_id=?", (pedido_id,))
                return self.send_json([dict(row) for row in c.fetchall()])

            elif action == 'usuarios':
                if method == 'GET':
                    c.execute("SELECT id, usuario, activo FROM usuarios ORDER BY id")
                    return self.send_json([dict(row) for row in c.fetchall()])
                elif method == 'POST':
                    c.execute("INSERT INTO usuarios (usuario, clave, activo) VALUES (?, ?, ?)",
                              (body.get('usuario',''), body.get('clave',''), int(body.get('activo', 1))))
                    conn.commit()
                    return self.send_json({'id': c.lastrowid, 'success': True})
                elif method == 'PUT':
                    c.execute("UPDATE usuarios SET usuario=?, clave=?, activo=? WHERE id=?",
                              (body.get('usuario',''), body.get('clave',''), int(body.get('activo', 1)), body.get('id', 0)))
                    conn.commit()
                    return self.send_json({'success': True})
                elif method == 'DELETE':
                    c.execute("DELETE FROM usuarios WHERE id=?", (body.get('id', 0),))
                    conn.commit()
                    return self.send_json({'success': True})

            return self.send_json({'error': f'Acción no válida: {action}'}, 404)

        finally:
            conn.close()

    def do_GET(self):
        if self.is_api_path():
            self.handle_api('GET')
        else:
            super().do_GET()

    def do_POST(self):
        if self.is_api_path():
            self.handle_api('POST')
        else:
            self.send_error(501, "Unsupported method")

    def do_PUT(self):
        if self.is_api_path():
            self.handle_api('PUT')
        else:
            self.send_error(501, "Unsupported method")

    def do_DELETE(self):
        if self.is_api_path():
            self.handle_api('DELETE')
        else:
            self.send_error(501, "Unsupported method")

if __name__ == '__main__':
    init_db()
    server_address = ('127.0.0.1', 8000)
    httpd = HTTPServer(server_address, MeggRequestHandler)
    print("Servidor local MEGG iniciado en http://127.0.0.1:8000")
    httpd.serve_forever()
