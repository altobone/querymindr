export const API_BASE = "https://musicsavvy.com/wp-json/the-loft/v1";

export interface Instrument {
  id: number;
  name: string;
}

export interface PresignResponse {
  upload_url: string;
  object_key: string;
}

export interface AuthResponse {
  token: string;
  username: string;
}

export interface SubmitPayload {
  s3_object_key?: string;
  submission_video_url?: string;
  submission_video_sample_start?: string;
  what_doesnt_feel_right: string;
  what_would_you_like_to_improve: string;
  instrument: number;
}

export async function loginWithCredentials(
  username: string,
  password: string
): Promise<AuthResponse> {
  const res = await fetch(`${API_BASE}/auth`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ username, password }),
  });
  if (res.status === 401 || res.status === 403) {
    throw new Error("invalid_credentials");
  }
  if (!res.ok) {
    throw new Error(res.status >= 500 ? "server_error" : `HTTP ${res.status}`);
  }
  return res.json() as Promise<AuthResponse>;
}

export async function registerUser(
  username: string,
  email: string,
  password: string
): Promise<AuthResponse> {
  const res = await fetch(`${API_BASE}/register`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ username, email, password }),
  });
  if (!res.ok) {
    let code = "";
    let message = "";
    try {
      const body = await res.json();
      code = body?.code ?? "";
      message = body?.message ?? "";
    } catch {}
    if (code === "username_exists") throw new Error("username_exists");
    if (code === "email_exists") throw new Error("email_exists");
    if (code === "registration_disabled") throw new Error("registration_disabled");
    if (code === "password_too_short") throw new Error("password_too_short");
    throw new Error(message || (res.status >= 500 ? "server_error" : `HTTP ${res.status}`));
  }
  return res.json() as Promise<AuthResponse>;
}

export async function fetchInstruments(authHeader: string): Promise<Instrument[]> {
  const res = await fetch(`${API_BASE}/instruments`, {
    headers: { Authorization: authHeader },
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(text || `HTTP ${res.status}`);
  }
  return res.json() as Promise<Instrument[]>;
}

export async function presignUpload(
  filename: string,
  authHeader: string
): Promise<PresignResponse> {
  const res = await fetch(`${API_BASE}/presign`, {
    method: "POST",
    headers: {
      Authorization: authHeader,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ filename }),
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(text || `HTTP ${res.status}`);
  }
  return res.json() as Promise<PresignResponse>;
}

export async function uploadToS3(
  uploadUrl: string,
  audioUri: string
): Promise<void> {
  const audioRes = await fetch(audioUri);
  const blob = await audioRes.blob();
  const s3Res = await fetch(uploadUrl, {
    method: "PUT",
    headers: { "Content-Type": "audio/mp4" },
    body: blob,
  });
  if (!s3Res.ok) {
    throw new Error(`S3 upload failed: HTTP ${s3Res.status}`);
  }
}

export async function submitForm(
  payload: SubmitPayload,
  authHeader: string
): Promise<void> {
  const res = await fetch(`${API_BASE}/submit`, {
    method: "POST",
    headers: {
      Authorization: authHeader,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });
  if (!res.ok) {
    let msg = `HTTP ${res.status}`;
    try {
      const body = await res.json();
      if (body?.message) msg = body.message;
    } catch {}
    throw new Error(msg);
  }
}
