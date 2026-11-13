require("dotenv").config();
const express = require("express");
const session = require("express-session");
const path = require("path");
const axios = require("axios");

const app = express();
const PORT = process.env.PORT || 80;
const PHP_API_URL = process.env.PHP_API_URL || "http://backend:8000/api";
const API_KEY = process.env.API_KEY || "claveapi"; // Debe coincidir con la de PHP
const ID_RE = /^[a-f0-9]{24}$/;

app.set("view engine", "ejs");
app.set("views", path.join(__dirname, "views"));

app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(express.static(path.join(__dirname, "public")));

app.use(
  session({
    secret: process.env.SESSION_SECRET || "clavesecretanode",
    resave: false,
    saveUninitialized: false,
    cookie: { maxAge: 4 * 60 * 60 * 1000 }
  })
);

// Cliente Axios: envía la API key y el rol de la sesión (PHP lo usa para las rutas de admin)
const apiPhp = (req) =>
  axios.create({
    baseURL: PHP_API_URL,
    headers: { "x-api-key": API_KEY, "x-user-role": req.session.user?.role || "" }
  });
const apiError = (error, fallback) => error.response?.data?.error || fallback;
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
const setFlash = (req, type, msg) => { req.session.flash = { type, msg }; };

// Variables disponibles en todas las vistas
app.use(async (req, res, next) => {
  res.locals.user = req.session.user || null;
  res.locals.cartCount = 0;
  if (req.session.user?.role === "comprador") {
    try {
      const r = await apiPhp(req).get("/cart/summary", { params: { username: req.session.user.username } });
      res.locals.cartCount = r.data.count;
    } catch {}
  }
  res.locals.flash = req.session.flash || null;
  res.locals.money = (n) => new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" }).format(n);
  res.locals.fmtDate = (d) =>
    new Date(d).toLocaleString("es-ES", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Madrid" });
  delete req.session.flash;
  next();
});

// Guardas de acceso
const requireLogin = (req, res, next) => (req.session.user ? next() : res.redirect("/login"));
const requireBuyer = (req, res, next) => {
  if (!req.session.user) return res.redirect("/login");
  if (req.session.user.role === "administrador") return res.redirect("/admin");
  next();
};
const requireAdmin = (req, res, next) => {
  if (!req.session.user || req.session.user.role !== "administrador") return res.redirect("/login");
  next();
};

// ---------- Inicio ----------
app.get("/", (req, res) => res.render("index"));

// ---------- Registro ----------
app.get("/register", (req, res) => {
  if (req.session.user) return res.redirect("/");
  res.render("register", { error: null, success: null });
});

app.post("/register", async (req, res) => {
  const { username, password, password_confirm } = req.body;

  if (password !== password_confirm) {
    return res.render("register", { error: "Las contraseñas no coinciden", success: null });
  }

  try {
    const response = await apiPhp(req).post("/auth/register", { username, password });
    if (response.data.ok) {
      return res.render("register", { error: null, success: "¡Cuenta creada! Ya puedes iniciar sesión." });
    }
    res.render("register", { error: "No se pudo crear la cuenta", success: null });
  } catch (error) {
    res.render("register", { error: apiError(error, "Error de comunicación con el backend"), success: null });
  }
});

// ---------- Login / logout ----------
app.get("/login", (req, res) => {
  if (req.session.user) return res.redirect("/");
  res.render("login", { error: null });
});

app.post("/login", async (req, res) => {
  const { username, password } = req.body;

  try {
    const response = await apiPhp(req).post("/auth/login", { username, password });

    if (response.data.ok) {
      req.session.user = { username: response.data.username, role: response.data.role };
      return res.redirect(response.data.role === "administrador" ? "/admin" : "/shop");
    }
    res.render("login", { error: "Credenciales incorrectas" });
  } catch (error) {
    res.render("login", { error: apiError(error, "Credenciales incorrectas o error en la API") });
  }
});

app.get("/logout", (req, res) => {
  req.session.destroy(() => res.redirect("/login"));
});

// ---------- Tienda (catálogo) ----------
app.get("/shop", requireLogin, wrap(async (req, res) => {
  const q = String(req.query.q || "").trim();
  const category = String(req.query.category || "");
  let products = [], categories = [], error = null;
  try {
    const r = await apiPhp(req).get("/products", { params: { q, category } });
    products = r.data.products;
    categories = r.data.categories;
  } catch (e) {
    error = apiError(e, "No se pudo cargar el catálogo.");
  }
  res.render("shop", { products, categories, q, category, error });
}));

// ---------- Carrito (vive en MongoDB; añadir reserva stock, ver CART_TTL_MINUTES en el backend) ----------
const buyer = (req) => req.session.user.username;

app.get("/cart", requireBuyer, wrap(async (req, res) => {
  let lines = [], total = 0, minutesLeft = null, error = null;
  try {
    const r = await apiPhp(req).get("/cart", { params: { username: buyer(req) } });
    lines = r.data.lines.map((l) => ({ ...l, subtotal: l.product.price * l.quantity }));
    total = lines.reduce((s, l) => s + l.subtotal, 0);
    if (r.data.expiresAt) minutesLeft = Math.max(1, Math.ceil((new Date(r.data.expiresAt) - Date.now()) / 60000));
  } catch (e) {
    error = apiError(e, "No se pudo cargar el carrito.");
  }
  res.render("cart", { lines, total, minutesLeft, error });
}));

app.post("/cart/add", requireBuyer, wrap(async (req, res) => {
  const { productId } = req.body;
  const quantity = Math.min(99, Math.max(1, parseInt(req.body.quantity, 10) || 1));
  if (!ID_RE.test(productId || "")) {
    setFlash(req, "error", "Producto no válido.");
    return res.redirect("/shop");
  }
  try {
    await apiPhp(req).post("/cart/items", { username: buyer(req), productId, quantity });
    setFlash(req, "success", "Añadido al carrito. Las unidades quedan reservadas para ti.");
  } catch (e) {
    setFlash(req, "error", apiError(e, "No se pudo añadir al carrito."));
  }
  res.redirect(req.get("referer") || "/shop");
}));

app.post("/cart/update", requireBuyer, wrap(async (req, res) => {
  const quantity = Math.max(0, parseInt(req.body.quantity, 10) || 0);
  if (ID_RE.test(req.body.productId || "")) {
    try {
      await apiPhp(req).put(`/cart/items/${req.body.productId}`, { username: buyer(req), quantity });
    } catch (e) {
      setFlash(req, "error", apiError(e, "No se pudo actualizar el carrito."));
    }
  }
  res.redirect("/cart");
}));

app.post("/cart/remove", requireBuyer, wrap(async (req, res) => {
  if (ID_RE.test(req.body.productId || "")) {
    try {
      await apiPhp(req).delete(`/cart/items/${req.body.productId}`, { params: { username: buyer(req) } });
    } catch (e) {
      setFlash(req, "error", apiError(e, "No se pudo quitar el producto."));
    }
  }
  res.redirect("/cart");
}));

// Enviar el carrito completo como solicitud de compra (el admin decide)
app.post("/cart/checkout", requireBuyer, wrap(async (req, res) => {
  try {
    await apiPhp(req).post("/orders", { username: buyer(req) });
    setFlash(req, "success", "Solicitud enviada. Un administrador la revisará pronto.");
    res.redirect("/orders");
  } catch (e) {
    setFlash(req, "error", apiError(e, "No se pudo enviar la solicitud de compra."));
    res.redirect("/cart");
  }
}));

// ---------- Mis pedidos ----------
app.get("/orders", requireBuyer, wrap(async (req, res) => {
  let orders = [], error = null;
  try {
    const r = await apiPhp(req).get("/orders", { params: { username: req.session.user.username } });
    orders = r.data.orders;
  } catch (e) {
    error = apiError(e, "No se pudieron cargar tus pedidos.");
  }
  res.render("orders", { orders, error });
}));

// ---------- Administración (provisional) ----------
app.get("/admin", requireAdmin, (req, res) => {
  res.send('<h1>Panel de administración</h1><p><a href="/logout">Salir</a></p>');
});

app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).send("Error interno del servidor");
});

app.listen(PORT, () => console.log(`Frontend de la tienda corriendo en el puerto ${PORT}`));
