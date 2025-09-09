import dotenv from 'dotenv';
dotenv.config();
import pg from 'pg';
const { Pool } = pg;
import { drizzle } from 'drizzle-orm/node-postgres';
import * as schema from "@shared/schema";

if (!process.env.DATABASE_URL) {
  throw new Error(
    "DATABASE_URL must be set. Did you forget to provision a database?",
  );
}

// Force connection to Supabase database with 107 tickets
const supabaseConnectionString = `postgresql://postgres.vbdwchdphecccissdymg:${encodeURIComponent('Cybaem@2025')}@aws-0-ap-south-1.pooler.supabase.com:6543/postgres`;

console.log('🔗 Connecting to Supabase database...');
export const pool = new Pool({ 
  connectionString: supabaseConnectionString,
  ssl: { rejectUnauthorized: false },
  max: 20,
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 10000,
});
export const db = drizzle(pool, { schema });