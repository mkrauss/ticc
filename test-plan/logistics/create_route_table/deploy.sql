set search_path="logistics";

create table "route" (
    "route" text primary key
  , "supplier" text references "supply"."supplier"
);
