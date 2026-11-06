require("dotenv").config();
const express = require("express");
const session = require("express-session");
const path = require("path");
const axios = require("axios");

const app = express();
const PORT = process.env.PORT || 80;
const PHP_API_URL = process.env.PHP_API_URL || "http://backend:8000/api";
const API_KEY = "claveapi"; // Debe coincidir con la de PHP

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

// Configuración de Axios
const apiPhp = axios.create({
  baseURL: PHP_API_URL,
  headers: { "x-api-key": API_KEY }
});

// Página de Inicio
app.get("/", (req, res) => {
  res.render("index", { user: req.session.user || null });
});

// Renderiza el formulario
app.get("/register", (req, res) => {
  if (req.session.user) return res.redirect("/");
  res.render("register", { error: null, success: null });
});

// Procesa el formulario
app.post("/register", async (req, res) => {
  const { username, password, password_confirm } = req.body;

  if (password !== password_confirm) {
    return res.render("register", { error: "Las contraseñas no coinciden", success: null });
  }

  try {
    const response = await apiPhp.post("/auth/register", { username, password });
    if (response.data.ok) {
      res.render("register", { error: null, success: "¡Cuenta creada! Ya puedes iniciar sesión." });
    }
  } catch (error) {
    const msg = error.response?.data?.error || "Error de comunicación con el backend";
    res.render("register", { error: msg, success: null });
  }
});

// Renderiza el formulario
app.get("/login", (req, res) => {
  if (req.session.user) return res.redirect("/");
  res.render("login", { error: null });
});

// Procesa el formulario
app.post("/login", async (req, res) => {
  const { username, password } = req.body;

  try {
    const response = await apiPhp.post("/auth/login", { username, password });
    
    if (response.data.ok) {
      // Guarda la sesión de forma nativa en Node
      req.session.user = {
        username: response.data.username,
        role: response.data.role
      };

      if (response.data.role === "administrador") {
        return res.redirect("/admin");
      }
      return res.redirect("/shop");
    }
  } catch (error) {
    const msg = error.response?.data?.error || "Credenciales incorrectas o error en la API";
    res.render("login", { error: msg });
  }
});

// Ruta de cierre de sesión
app.get("/logout", (req, res) => {
  req.session.destroy();
  res.redirect("/login");
});

// Provisional
app.get("/shop", (req, res) => {
  if (!req.session.user) return res.redirect("/login");
  res.send(`<h1>Página de Tienda - Bienvenido ${req.session.user.username}</h1><a href="/logout">Salir</a>`);
});

app.get("/admin", (req, res) => {
  if (!req.session.user || req.session.user.role !== "administrador") return res.redirect("/login");
  res.send(`<h1>Panel de Administración - Bienvenido ${req.session.user.username}</h1><a href="/logout">Salir</a>`);
});

app.listen(PORT, () => console.log(`Frontend de la tienda corriendo en el puerto ${PORT}`));
