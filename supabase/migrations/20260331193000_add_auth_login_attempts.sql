begin;

create table if not exists public.auth_login_attempts (
    attempt_key varchar(255) primary key,
    school_code varchar(50) not null,
    email varchar(120) not null,
    ip_address varchar(64) not null,
    failure_count integer not null default 0,
    first_failed_at timestamp not null,
    last_failed_at timestamp not null,
    blocked_until timestamp,
    created_at timestamp not null default current_timestamp,
    updated_at timestamp not null default current_timestamp
);

create index if not exists idx_auth_login_attempts_blocked_until
    on public.auth_login_attempts (blocked_until);

commit;
