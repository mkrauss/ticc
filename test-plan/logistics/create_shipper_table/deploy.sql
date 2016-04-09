set schema "logistics";

create table "route" (
    "route" text primary key
  , "supplier" text references "supply"."supplier"
);
