set search_path="supply";

create table "supplier" (
  primary key ("supplier")

  , "supplier" text
  , "contracted_during" tstzrange
);
