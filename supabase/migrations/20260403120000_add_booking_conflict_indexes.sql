create index if not exists idx_bookings_resource_date_status
on public.bookings (resource_id, booking_date, status);

alter table public.bookings
add column if not exists idempotency_key varchar(100);

create unique index if not exists uniq_bookings_school_user_idempotency
on public.bookings (school_id, user_id, idempotency_key)
where idempotency_key is not null;

create or replace function public.prevent_overlapping_scheduled_booking_lessons()
returns trigger
language plpgsql
as $$
declare
    current_booking record;
begin
    select
        school_id,
        resource_id,
        booking_date,
        status
    into current_booking
    from public.bookings
    where id = new.booking_id;

    if not found or current_booking.status <> 'scheduled' then
        return new;
    end if;

    if exists (
        select 1
        from public.booking_lessons bl
        inner join public.bookings b on b.id = bl.booking_id
        where b.school_id = current_booking.school_id
          and b.resource_id = current_booking.resource_id
          and b.booking_date = current_booking.booking_date
          and b.status = 'scheduled'
          and bl.lesson_slot_id = new.lesson_slot_id
          and bl.booking_id <> new.booking_id
    ) then
        raise exception 'BOOKING_CONFLICT: scheduled slot already reserved'
            using errcode = 'P0001',
                  detail = json_build_object(
                      'resource_id', current_booking.resource_id,
                      'booking_date', current_booking.booking_date,
                      'lesson_slot_id', new.lesson_slot_id
                  )::text;
    end if;

    return new;
end;
$$;

drop trigger if exists trg_prevent_overlapping_scheduled_booking_lessons on public.booking_lessons;
create trigger trg_prevent_overlapping_scheduled_booking_lessons
before insert or update of booking_id, lesson_slot_id
on public.booking_lessons
for each row
execute function public.prevent_overlapping_scheduled_booking_lessons();
