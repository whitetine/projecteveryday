// server.js
const express = require("express");
const cors = require("cors");
const helmet = require("helmet");
const rateLimit = require("express-rate-limit");
const jwt = require("jsonwebtoken");
const bcrypt = require("bcryptjs");
const { MongoClient } = require("mongodb");
require("dotenv").config();

const app = express();
app.use(express.json({ limit: "1mb" }));
app.use(helmet());
const allowlist = [
  "https://projecteveryday.infinityfreeapp.com",
  "http://localhost",
  "http://127.0.0.1",
];

app.use(cors({
  origin: function (origin, cb) {
    // 沒有 origin 代表可能是 curl/postman
    if (!origin) return cb(null, true);
    if (allowlist.some(u => origin.startsWith(u))) return cb(null, true);
    return cb(new Error("Not allowed by CORS: " + origin));
  },
  credentials: false,
}));


app.use(
  rateLimit({
    windowMs: 60 * 1000,
    max: 120,
  })
);

const API_KEY = process.env.API_KEY || "";
const JWT_SECRET = process.env.JWT_SECRET || "";
const MONGO_URI = process.env.MONGO_URI || "";
const DB_NAME = process.env.MONGO_DB || "projecteverydays";

if (!API_KEY || !JWT_SECRET || !MONGO_URI) {
  console.error("❌ Missing env: API_KEY / JWT_SECRET / MONGO_URI");
  process.exit(1);
}

let db;

async function initMongo() {
  const client = new MongoClient(MONGO_URI);
  await client.connect();
  db = client.db(DB_NAME);
  console.log("✅ Mongo connected:", DB_NAME);
}

function requireApiKey(req, res, next) {
  const key = req.header("x-api-key");
  if (!key || key !== API_KEY) return res.status(401).json({ ok: false, msg: "Invalid API key" });
  next();
}

function requireJwt(req, res, next) {
  const auth = req.header("authorization") || "";
  const token = auth.startsWith("Bearer ") ? auth.slice(7) : "";
  if (!token) return res.status(401).json({ ok: false, msg: "Missing token" });

  try {
    req.user = jwt.verify(token, JWT_SECRET);
    next();
  } catch {
    return res.status(401).json({ ok: false, msg: "Invalid token" });
  }
}

// 健康檢查
// app.get("/health", requireApiKey, (req, res) => res.json({ ok: true, msg: "alive" }));
app.get("/health", (req, res) => res.json({ ok: true, msg: "alive" }));

// 登入（先用簡化版：比對明碼密碼 u_password）
app.post("/auth/login", requireApiKey, async (req, res) => {
  const { account, password } = req.body || {};
  if (!account || !password) return res.status(400).json({ ok: false, msg: "Missing account/password" });

  const users = db.collection("userdata"); // 先假設你集合叫 userdata
  const u = await users.findOne({ u_ID: account });

  if (!u) return res.json({ ok: false, msg: "帳號或密碼錯誤" });

  // ⚠️ 暫時先用明碼比對（你 MySQL 匯入過來很可能就是明碼）
  // 等跑起來再改 bcrypt hash
  const ok = (u.u_password ?? "") === password;

  if (!ok) return res.json({ ok: false, msg: "帳號或密碼錯誤" });

  const payload = {
    u_ID: u.u_ID,
    u_name: u.u_name || "",
    role_ID: u.role_ID ?? 0,
  };

  const token = jwt.sign(payload, JWT_SECRET, { expiresIn: "7d" });

  res.json({ ok: true, token, user: payload });
});

// 讀自己的 token 資訊
app.get("/users/me", requireApiKey, requireJwt, (req, res) => {
  res.json({ ok: true, user: req.user });
});

const port = process.env.PORT || 3000;

initMongo()
  .then(() => app.listen(port, () => console.log("🚀 API running on", port)))
  .catch((e) => {
    console.error("❌ Mongo init failed:", e);
    process.exit(1);
  });
