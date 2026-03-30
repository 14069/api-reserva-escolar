begin;

alter table public.bookings
    add column if not exists completion_feedback text;

commit;
